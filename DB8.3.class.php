<?php

// DB v8.3 
//----------------------------------------------------------
// Created by Steven Crombie

/* CHANGES:
v8.3	(Vendor Portal and MerchPortal only!!)
	10/15/2018: DOES NOT INCLUDE 8.2 CHANGES
				Deleted paging functions
				Changed constructor parameters to include auth connection
				Nulled $stmt (PDO connection object) after query, insert, update, delete, execsp

v8.2  (DO NOT USE -- BROKEN)
	10/09/2018:	Changed constructor parameters to array
				Allow passing connection ini as parameter for auth data
				Nulled $stmt (PDO connection object) after query, insert, update, delete, execsp
				Removed lazy connecting, connect in constructor
v8.1
	07/14/2018: Added JWT functionality to create and validate
				Add authentication from table
				Added password hashing
v7.0
	06/30/2018: Change parameters for functions from 3 parameters to named array
				Added auth_msint function to return current domain login name
v6.0
	06/29/2018: Remove error output leaving standard response from server
				*** Constructor requires second parameter (true/false) for show errors.
				Removed queryPage, recordCount, pageCount functions.  Not used for a long time.
v5.4
	07/22/2017: Add AD authentication
				Added example ini file format in comments
v5.3
	07/21/2017: Changed from using SQL Server specific variables to get counts/id
				for INSERT, UPDATE, DELETE to using PDO functions.
				Different connections for sqlsvr, mssql, mysql based on drvr value.
v5.2
	07/14/2017: Updated execsp function to return all records sets.
				Noted to use none when no records sets returned.	

v5.1
	08/24/2016: Added formatData() to call json_encode and wrap in callback if present
	            Added PDO:SQLSRV_ATTR_QUERY_TIMEOUT to _getConn()
				Deprecated queryPage(), recordCount(), and pageCount().
				Removed version from class name.  Now just DB.
*/


/* EXAMPLE USAGE WITH PHP SLIM2 FRAMEWORK
$app->get('/test',
    function() use($app) {
        $cnn = new DB5(CONN, false);  // CONN is defined constant of .ini filename
        $q["typ"]  = "qry";
        $q["sql"] = "SELECT Id, Text, Memo FROM Test ORDER BY Id";
        $q["par"] = array();
        $res = $cnn->query($q);
        echo $cnn->formatData($res);  // support both json and jsonp
        $cnn = null;
    }
);
*/
/* EXAMPLE USAGE IN PHP FILE
$conn = new DB("Intranet.rw.ini", false);
$typ = "qry";

$sql = "SELECT TOP 100 * FROM tblLogins";
$par = array();
$res = $conn->query($typ, $sql, $par);


foreach($res as $r) {
	echo $r["Username"]."<br/>";
}
*/

include "/var/www/php-includes/resources/FirebasePhpJwt/JWT.php";

class DB {

    protected $_connection;
    protected $_config;

    /*  Constructor
     *  1 parameter: connection file
     *  2 parameter: auth connection file (ex. auth_ubox3.ini)
     *  3 parameter: display errors text (true/false)
     */
    public function __construct($dbini, $authini, $displayerrors) {
      $this->_config = parse_ini_file($dbini,true);
	  $this->_authconfig = parse_ini_file($authini,true);
      $this->displayerrors = $displayerrors;
      // Reference Example: $this->_config['db']['drvr']
      /*  SAMPLE FILE FORMAT
        [db]
        drvr = sqlsrv
        host = whfd-sql2005
        db = Peruse
        usr = myusername
        pwd = mypassword
      */
    }

    public function query($q) {  
        // $q is array (typ, sql, par as array)
        $count = 0;
        try
        {
        $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
        $count = $stmt->execute($q["par"]);
        }
        catch (PDOException $e)
        { 
          if($this->displayerrors) {
            echo ('<p style="color:red;">Database query failed.<br/>');
            echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
            return "Fail!";
          }
        }
        if(!$count)
            return false;

        if($q["typ"] <> "get")
            $stmt->setFetchMode(PDO::FETCH_ASSOC);

        switch($q["typ"]) {
           case "get":
                $data = $stmt->fetch();
                $data = $data[0];
                break;
            case "sel": 
                $data = $stmt->fetch();
                break;
            case "cnt":
                $data = $count;
                break;
            default:
                $data = $stmt->fetchAll();   // case: "qry"
        }
		$stmt = null;
        return $data;
    }


// --------------------------------------------------------------------
    public function insert($q) {
      // Insert record
      $ins = 0;
      try
      {
          $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
          $ins = $stmt->execute($q["par"]);
      }
      catch (PDOException $e)
      {
        if($this->displayerrors) {
          echo ('<p style="color:red;">Database query failed.<br/>');
          echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
          echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
        }
      }
      if($ins !== false) {
        $id = $this->_getConn()->lastInsertId();  // requires an auto-increment field (PDO function -- not available in mssql) 
        //$stmt = $this->_getConn()->query("SELECT @@IDENTITY");  // SQL Server only version line 1
        //$id = $stmt->fetchColumn();  // SQL Server only version line 2
		$stmt = null;
        return $id;
      }
    }

// --------------------------------------------------------------------
    function update ($q) {
        // Update record
        $upd = 0;
        try
        {
            $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
            $upd = $stmt->execute($q["par"]);
        }
        catch (PDOException $e)
        {
          if($this->displayerrors) {
            echo ('<p style="color:red;">Database query failed.<br/>');
            echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
          }
        }
        if($upd !== false) 
			$stmt = null;
            return $upd;  // returns number of rows affected
    }

// --------------------------------------------------------------------
	public function upsert ($q) {  // $upd_sql, $upd_par, $ins_sql, $ins_par
		$upd = $this->update($q["upd_sql"], $q["upd_par"]);
		if(!$upd) {
			$ins = $this->insert($q["ins_sql"], $q["ins_par"]);
			$ret = array("insert", $ins);  // return array including id
		} else {
			$ret = array("update", $upd);  // return array including count
		}
		return $ret;
	}

// --------------------------------------------------------------------
    public function delete ($q) {
        // Delete record
        $del = 0;
        try
        {
          $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
          $del = $stmt->execute($q["par"]);
        }
        catch (PDOException $e)
        {
          if($this->displayerrors) {
            echo ('<p style="color:red;">Database query failed.<br/>');
            echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
          }
        }
        if($del !== false) {
          $count = $stmt->rowCount();
		  $stmt = null;
          return $count;  // returns number of rows affected
        }
    }
    
    public function execsp($q) {
        // Execute stored procedure
        $count = 0;
        try
        {
          $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
          $count = $stmt->execute($q["par"]);
        }
        catch (PDOException $e)
        {
          if($this->displayerrors) {
            echo ('<p style="color:red;">Database query failed.<br/>');
            echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
            return "Fail!";
          }
        }
        
        // NOT ALL SPs RETURN record sets (use 'none' for this case)
        if(!$count)
            return false;

        switch($q["typ"]) {
          case "get":
              $data = $stmt->fetch();
              $data = $data[0];
              break;	
          case "sel": 
              $data = $stmt->fetch();
              break;
          case "cnt":
              $data = $count;
              break;
          case "none":
              $data = true;
              break;
          default:
            //$data = $stmt->fetchAll(); 
            // get all record sets
            $data = array();
            do {
              $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
              if ($rs) {
                $data[] = $rs;
              }
            } while (
              $stmt->nextRowset()
            );				
         }
		$stmt = null;
        return $data;
    }

// ----------------------------------------------------------------------------------

	// jsonp encode data if callback defined in URL	else json encode data
  // supports both json and jsonp
  // see example at beginning of file
	public function formatData($data) {
		$callback = isset($_GET["callback"]) ? $_GET["callback"] : NULL ;
		$data = json_encode($data);
		if($callback) {
			return $callback."(".$data.")";
		} else {
			return $data;
		}
	}
	
//-------------------------------------------------    
	// ***** AUTHENTICATE VIA ACTIVE DIRECTORY *****
    public function auth_ad($user, $pass) {
      // *** Don't transfer passwords clear-text.  Use SSL. ***
      // ldap extension must be enabled on server
      if (substr($user, 0, 8) != "FARMERS\\") {
        $user = "FARMERS\\" . $user;
      }
      $server = "10.200.100.40";  // farmers.intranet
      $ldap = @ldap_connect($server);

      if (@ldap_bind($ldap, $user, $pass)) {
        ldap_unbind($ldap);
        $auth = 1;
      } else {
        $auth = 0;
      }
      if (strlen($pass) == 0) {
        $auth = 0;
      }
      return $auth;
    }

	// ***** AUTHENTICATE VIA ACTIVE DIRECTORY AND RETURN JWT *****	
    public function auth_ad_jwt ($user, $pass, $jwtpar) {  // $jwtpar is array containing: nbf_mins, exp_mins, sub
		$auth = $this->auth_ad($user, $pass);
		if($auth) {
			$userid = $this->getUserId($user, $jwtpar["sub"]);
			$jwt = $this->createJwt($userid, $user, $pass, $jwtpar);  			
		} else {
			$userid = 0;
			$jwt = 0;
		}
		/*
		*/
		//return array($userid, $jwt);
		//return array(123, "abcdef");
		return array($userid, $jwt);
    }
	
	// ***** AUTHENTICATE VIA DATABASE *****
	public function auth_db($user, $pass, $app) {
		//$pwhash = hashPassword($pass, $app);
		$auth = $this->checkpw_db_extaccess($user, $pass, $app);  // array($userid, $valid)
		return $auth[1];  // 0 or 1
	}
  
	// ***** AUTHENTICATE VIA DATABASE AND RETURN JWT *****
	public function auth_db_jwt($user, $pass, $jwtpar) {  
		// * $jwtpar is array containing: nbf, exp, sub (app name), iss (database name)
		//echo "user: ".$user."\n";
		//echo "pass: ".$pass."\n";
		//echo "app: ".$jwtpar["iss"]."\n";
		$auth = $this->auth_db($user, $pass, $jwtpar["sub"]); 
		//echo "auth: ".$auth."\n";
		$userid = $this->getUserId($user, $jwtpar["sub"]);
		//echo "userid: ".$userid."\n";
		if($auth) {
			$jwt = $this->createJwt($userid, $user, $pass, $jwtpar);  
		} else {
			$userid = 0;
			$jwt = 0;
		}
		return array($userid, $jwt);
	}
    
	public function getUserId($username, $appname) { // TODO: change to private function
		$q["sql"] = "SELECT TOP 1 Id FROM authLogins WHERE Username = ? AND AppName = ? AND Active = 'Y'";
		$q["par"] = array($username, $appname);
		$stmt = $this->_getAuthConn()->prepare($q["sql"]);
		$params = $stmt->execute($q["par"]);
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		$userid = $stmt->fetch();
		return $userid["Id"];
	}
    
	private function checkpw_db_extaccess($user, $pass, $app) {
		$q["sql"] = "SELECT Id, PwHash FROM authLogins WHERE UserName = ? AND AppName = ? and Active = 'Y'";
		$q["par"] = array($user, $app);
		$stmt = $this->_getAuthConn()->prepare($q["sql"]);
		$params = $stmt->execute($q["par"]);
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		$data = $stmt->fetch();
		$userid = $data["Id"];
		$pwhash = $data["PwHash"];
		//echo "userid: ".$userid."\n";
		//echo "pwhash: ".$pwhash."\n";
		$valid =  password_verify($pass, $pwhash) ? 1 : 0 ;
		return array($userid, $valid);
	}

	private function auth_msint() {  // returns DOMAIN login for sites without anonymous access and with MS integrated security enabled 
		return $_SERVER['REMOTE_USER'];  // has domain prefix of "FARMERS\"
	}

// ----------------------------------------------------------------------------------
// Login passwords

	public function hashPassword($pw) {  
		$pwhash = password_hash($pw, PASSWORD_BCRYPT); // requires php >= 5.5
		return $pwhash;
	}
	
	public function createTempPasswordAndHash() {
		$length = 16;
		$pw = substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);;
		$pwhash = password_hash($pw, PASSWORD_BCRYPT);
		return array($pw, $pwhash);
	}
	
	/*
	private function verifyPassword($pass, $pwhash) {
		return password_verify($pass, $pwhash);
	}
	*/
	
// ----------------------------------------------------------------------------------
// JWTs

	private function base64UrlEncode($data) {
		$urlSafeData = strtr(base64_encode($data), '+/', '-_');
		return rtrim($urlSafeData, '='); 
	} 
	 
	private function base64UrlDecode($data) {  // returns named array
		$urlUnsafeData = strtr($data, '-_', '+/');
		$paddedData = str_pad($urlUnsafeData, strlen($data) % 4, '=', STR_PAD_RIGHT);
		return base64_decode($paddedData);
	}

// JWT functions
/* JWT parts: header, body (claims), signature
 * JWT claims: 
 *   nbf (not before time), 
 *   exp (expiration time), 
 *   sub (appname), 
 *   jti (token), 
 *   iss (server), 
 *   aud (array: username, location, roles, email),
 *   ipa (ip address)
 */
    public function createJwt($userid, $user, $pass, $jwtpar) {    // MAKE PRIVATE METHOD
      // $info is associative array 
      //   nbf in minutes, 
      //   exp in minutes, 
      //   sub as appname, 
      //   X ipa as ip address
      //   X jti as token,
      //   iss as server
      //   aud as array of username, location, roles, email
      //   X ipa as ip address
      //$secret = $this->createSecret();
	  $pwhash = $this->hashPassword($pass);
	  //echo "Create pwhash: ".$pwhash."\n\n";   // DELETE THIS <------------------------------
      $header = [
		"alg" => "HS256",
		"typ" => "JWT"
	  ];
	  
      $nbf = $this->nbfClaim($jwtpar["nbf"]);  // (1)
      $exp = $this->expClaim($jwtpar["exp"]);  // (2)
      $sub = $this->subClaim($jwtpar["sub"]);  // (3)
      $iss = $this->issClaim($jwtpar["iss"]);  // (5)
      $aud = $this->audClaim($user, $jwtpar["sub"]);  // (6)
      $body = [
        "nbf" => $nbf,
        "exp" => $exp,
        "sub" => $sub,
        "iss" => $iss,
        "aud" => json_encode($aud)
	  ];
	  $jwt = $this->createJwt2($header, $body, $pwhash);	
	  $update = $this->updatePwHash($userid, $pwhash);

      return $jwt;
    }
    
	private function createJwt2($header, $payload, $pwhash) {
		$algo = 'sha256';
		$headerEncoded = $this->base64UrlEncode(json_encode($header));
		$payloadEncoded = $this->base64UrlEncode(json_encode($payload));
		// Delimit with period (.)
		$dataEncoded = $headerEncoded.".".$payloadEncoded;
		$rawSignature = hash_hmac($algo, $dataEncoded, $pwhash, true);
		$signatureEncoded = $this->base64UrlEncode($rawSignature);
		// Delimit with second period (.)
		$jwt = "$dataEncoded.$signatureEncoded";
		return $jwt;
	}	
	
    private function nbfClaim($mins) {  // (1)
      $dt = time();
      $dt = $dt - (60 * $mins);
      return $dt;
    }
    
    private function expClaim($mins) {  // (2)
      $dt = time();
      $dt = $dt + (60 * $mins);
      return $dt;
    }
    
    private function subClaim($sub) {  // (3)
      return $sub;
    }
	
    private function issClaim($iss) {  // (5)
      return $iss;
    }
	
    private function audClaim($user, $app) {  // (6)  user, location, roles, email
      $q["typ"] = "sel";
      $q["sql"] = "SELECT Location, Roles, Email 
                   FROM authLogins
                   WHERE UserName = ?
                   AND AppName = ?";
      $q["par"] = array($user, $app);
      $stmt = $this->_getAuthConn()->prepare($q["sql"]);
      $params = $stmt->execute($q["par"]);
      $stmt->setFetchMode(PDO::FETCH_ASSOC);
      $data = $stmt->fetch();
      $aud = array(
        "username"=>$user,
        "location"=>$data["Location"],
        "roles"=>$data["Roles"],
        "email"=>$data["Email"]
      );
      return $aud;
    }
    
	private function updatePwHash($userid, $pwhash) {
		$qry = "UPDATE authLogins SET PwHash = ? WHERE Id = ?";
		$par = array($pwhash, $userid);
		$stmt = $this->_getAuthConn()->prepare($qry);  // Use lazy loading getter
		$data = $stmt->execute($par);  // or fetch?
	}
        
	//-------------------
	public function validateJwt($jwt, $appname, $server) {
		$error = 0;	
		$curDate = time();
		// (0) split JWT
		if(substr($jwt,0,7) == 'Bearer ') {
			$jwt = substr($jwt,7);
		}
		$jwtArray = explode(".", $jwt);
		if(isset($jwtArray[1])) {
			// (1) decode claims
			$claims = json_decode($this->base64UrlDecode($jwtArray[1]), true);  // Array  
			$aud = json_decode($claims["aud"]);  // Object
			// (2) check signature
			// (2.1) get password hash
			$pwhash = $this->getPasswordHash($aud->username, $claims["sub"]);
			//echo "Verify pwhash: ".$pwhash."\n\n";   // DELETE THIS <------------------------------
			// (2.2) client signature
			$clientSig = $jwtArray[2];
			//echo "Client Sig: ".$clientSig."\n";   // DELETE THIS <------------------------------
			// (2.3) server signature
			$algo = 'sha256';
			$encodedClaims = $jwtArray[0].".".$jwtArray[1];
			$rawSig = hash_hmac($algo, $encodedClaims, $pwhash, true);
			$serverSig = $this->base64UrlEncode($rawSig);
			//echo "Server Sig: ".$serverSig."\n";   // DELETE THIS <------------------------------
			// (2.4) signature comparison
			$isValidSignature = hash_equals($serverSig, $clientSig) ? 1 : 0 ;
			//$isValidSignature = trim($serverSig) == trim($clientSig) ? 1 : 0 ;
			// (3) check nbf
			$nbfClient = intval($claims["nbf"]);
			$isValidNbf = $curDate > $nbfClient ? 1 : 0 ;
			// (4) check exp
			$expClient = intval($claims["exp"]);
			$isValidExp = $curDate < $expClient ? 1 : 0 ;
			// (5) check sub
			$isValidSub = $claims["sub"] == $appname ? 1 : 0 ;
			// (6) check iss
			$isValidIss = $claims["iss"] == $server ? 1 : 0 ;
			$count = $isValidSignature + $isValidNbf + $isValidExp + $isValidSub + $isValidIss;
			$result = $count == 5 ? 1 : 0 ;
			//return array($result, $isValidSignature, $isValidNbf, $isValidExp, $isValidSub, $isValidIss, $jwt, $jwtArray, $clientSig, $serverSig);
			return array($result, $isValidSignature, $isValidNbf, $isValidExp, $isValidSub, $isValidIss);
		} else {
			return array(0);  // fail/invalid
		}
	}
	
	public function getPasswordHash($username, $appname) {    //  <----------- CHANGE TO PRIVATE METHOD
		$q["sql"] = "SELECT PwHash FROM authLogins WHERE Username = ? and AppName = ? and Active = 'Y'";
		$q["par"] = array($username, $appname);
		$stmt = $this->_getAuthConn()->prepare($q["sql"]);
		$params = $stmt->execute($q["par"]);
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		$data = $stmt->fetch();
		return $data["PwHash"];
	}
	
	
// ----------------------------------------------------------------------------------

    public function obj($q) {
      // sql should return only one row
      $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
      $params = $stmt->execute($q["par"]);
      $stmt->setFetchMode(PDO::FETCH_OBJECT);  // fetch_object instead of fetch_assoc
      $data = $stmt->fetch();
      return $data;
    }

    protected function _getConn() {
      // Lazy load connection
      $dsn = "";
      if($this->_connection === null) {
        if($this->_config['db']['drvr'] == 'mssql') {
          $dsn = "dblib:version:7.3;charset=UTF-8;host=".$this->_config['db']['host'].";dbname=".$this->_config['db']['db'].";"; // working mssql	
          try {
            $this->_connection = new PDO($dsn, $this->_config['db']['usr'], $this->_config['db']['pwd']);
            $this->_connection -> setAttribute(PDO::ATTR_TIMEOUT, 300);  // new in v5.3
            $this->_connection -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $cmd = $this->_connection->prepare('SET ANSI_WARNINGS ON');
            $cmd->execute();
            $cmd = $this->_connection->prepare('SET ANSI_NULLS ON');
            $cmd->execute();
          }
          catch(PDOException $e) {
            print "Connection to database failed!".$e;
            die();
          }
        }
        if($this->_config['db']['drvr'] == 'sqlsrv') {
          $dsn = "sqlsrv:Server=".$this->_config['db']['host'].";Database=".$this->_config['db']['db'];
          try {
            $this->_connection = new PDO($dsn, $this->_config['db']['usr'], $this->_config['db']['pwd']);
            $this->_connection -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->_connection -> setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 300);  // added 8/24/2016 [SC]
          }
          catch(PDOException $e) {
            print "Connection to database failed!".$e;
            die();
          }
        }
        if($this->_config['db']['drvr'] == 'mysql') {  // new in v5.3 (from DB4)
          $dsn = "mysql:host=".$this->_config['db']['host'].";dbname=".$this->_config['db']['db'];
          try {
            $this->_connection = new PDO($dsn, $this->_config['db']['usr'], $this->_config['db']['pwd']);
            $this->_connection -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->_connection -> setAttribute(PDO::MYSQL_ATTR_INIT_COMMAND, 'SET NAMES utf8');
          }
          catch(PDOException $e) {
            print "Connection to database failed!".$e;
            die();
          }
        }
      }
      return $this->_connection;
    }
	
    protected function _getAuthConn() {  // Added for 8.3 for jwt functionality (10/15/2018)
      // Lazy load connection
      $dsn = "";
      if($this->_connection === null) {
        if($this->_authconfig['db']['drvr'] == 'sqlsrv') {
          $dsn = "sqlsrv:Server=".$this->_authconfig['db']['host'].";Database=".$this->_authconfig['db']['db'];
          try {
            $this->_connection = new PDO($dsn, $this->_authconfig['db']['usr'], $this->_authconfig['db']['pwd']);
            $this->_connection -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->_connection -> setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 300);  // added 8/24/2016 [SC]
          }
          catch(PDOException $e) {
            print "Connection to database failed!".$e;
            die();
          }
        }
      }
      return $this->_connection;
    }
	
	/*   *** backup of working original function ***
    protected function _getConn() {
      // Lazy load connection
      $dsn = "";
      if($this->_connection === null) {
        if($this->_config['db']['drvr'] == 'mssql') {
          $dsn = "dblib:version:7.3;charset=UTF-8;host=".$this->_config['db']['host'].";dbname=".$this->_config['db']['db'].";"; // working mssql	
          try {
            $this->_connection = new PDO($dsn, $this->_config['db']['usr'], $this->_config['db']['pwd']);
            $this->_connection -> setAttribute(PDO::ATTR_TIMEOUT, 300);  // new in v5.3
            $this->_connection -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $cmd = $this->_connection->prepare('SET ANSI_WARNINGS ON');
            $cmd->execute();
            $cmd = $this->_connection->prepare('SET ANSI_NULLS ON');
            $cmd->execute();
          }
          catch(PDOException $e) {
            print "Connection to database failed!".$e;
            die();
          }
        }
        if($this->_config['db']['drvr'] == 'sqlsrv') {
          $dsn = "sqlsrv:Server=".$this->_config['db']['host'].";Database=".$this->_config['db']['db'];
          try {
            $this->_connection = new PDO($dsn, $this->_config['db']['usr'], $this->_config['db']['pwd']);
            $this->_connection -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->_connection -> setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 300);  // added 8/24/2016 [SC]
          }
          catch(PDOException $e) {
            print "Connection to database failed!".$e;
            die();
          }
        }
        if($this->_config['db']['drvr'] == 'mysql') {  // new in v5.3 (from DB4)
          $dsn = "mysql:host=".$this->_config['db']['host'].";dbname=".$this->_config['db']['db'];
          try {
            $this->_connection = new PDO($dsn, $this->_config['db']['usr'], $this->_config['db']['pwd']);
            $this->_connection -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->_connection -> setAttribute(PDO::MYSQL_ATTR_INIT_COMMAND, 'SET NAMES utf8');
          }
          catch(PDOException $e) {
            print "Connection to database failed!".$e;
            die();
          }
        }
      }
      return $this->_connection;
    }
	*/
	

	
	/*
	// ==========================================================================================================
	// ********************
	//    TESTING
	// ********************
		public function test_createJwt($user, $jwtpar, $db, $pass) {  
		// $info is associative array 
		//   nbf in minutes, 
		//   exp in minutes, 
		//   sub as appname, 
		//   ipa as ip address
		//   X jti as token,
		//   iss as server
		//   aud as array of username, location, roles, email
		//   X ipa as ip address
		//$secret = $this->createSecret();
		$pwhash = $this->hashPassword($pass);
		$pwhash = '$2y$10$Q.PIhvV5DZ1/fUKDkYzuleW/TiWPlpkPVamooc8Ea01TrGHRyjJeK';
		$header = [
		"alg" => "HS256",
		"typ" => "JWT"
		];

		$nbf = $this->nbfClaim($jwtpar["nbf"]);  // (1)
		$exp = $this->expClaim($jwtpar["exp"]);  // (2)
		$sub = $this->subClaim($jwtpar["sub"]);  // (3)
		$iss = $this->issClaim($db);  // (5)
		$aud = $this->audClaim($user, $jwtpar["sub"]);  // (6)
		$body = [
		"nbf" => $nbf,
		"exp" => $exp,
		"sub" => $sub,
		"iss" => $iss,
		"aud" => json_encode($aud),
		];
		$jwt0 = $this->test_createJwt2($header, $body, $pwhash);	
		$jwt = $jwt0[0];

		return array($jwt, $jwt0[1], $jwt0[2], $jwt0[3], $jwt0[4], $jwt0[5], $jwt0[6]);
    }

	private function test_createJwt2($header, $payload, $pwhash) {
		$algo = 'sha256';
		$headerEncoded = $this->base64UrlEncode(json_encode($header));
		$payloadEncoded = $this->base64UrlEncode(json_encode($payload));
		// Delimit with period (.)
		$dataEncoded = $headerEncoded.".".$payloadEncoded;
		$dataEncoded = "abc123.def456";
		$rawSignature = hash_hmac($algo, $dataEncoded, $pwhash, false);
		$signatureEncoded = $this->base64UrlEncode($rawSignature);
		// Delimit with second period (.)
		$jwt = "$dataEncoded.$signatureEncoded";
		return array($jwt, $pwhash, $headerEncoded, $payloadEncoded, $dataEncoded, $rawSignature, $signatureEncoded);
	}	

	public function test_validateJwt($jwt, $appname, $server) {
		$error = 0;	
		$curDate = time();
		// (0) split JWT
		$jwtArray = explode(".", $jwt);
		// (1) decode claims
		$claims = json_decode($this->base64UrlDecode($jwtArray[1]), true);  // Array
		$aud = json_decode($claims["aud"]);  // Object
		// (2) check signature
		// (2.1) get password hash
		//$pwhash = $this->getPasswordHash($aud->username, $claims["sub"]);
		$pwhash = $this->getPasswordHash('scrombie@farmersfurniture.com','vendorportal');
		// (2.2) client signature
		$clientSig = $jwtArray[2];
		// (2.3) server signature
		$algo = 'sha256';
		$encodedData = $jwtArray[0].".".$jwtArray[1];
		$encodedData = "abc123.def456";
		//$pwhash = "$2y$10$Q.PIhvV5DZ1/fUKDkYzuleW/TiWPlpkPVamooc8Ea01TrGHRyjJeK";
		$rawSig = hash_hmac($algo, $encodedData, $pwhash, false);
		$serverSig = $this->base64UrlEncode($rawSig);
		// (2.4) signature comparison
		$isValidSignature = hash_equals($serverSig, $clientSig) ? 1 : 0 ;
		// (3) check nbf
		$nbfClient = intval($claims["nbf"]);
		$isValidNbf = $curDate > $nbfClient ? 1 : 0 ;
		// (4) check exp
		$expClient = intval($claims["exp"]);
		$isValidExp = $curDate < $expClient ? 1 : 0 ;
		// (5) check sub
		$isValidSub = $claims["sub"] == $appname ? 1 : 0 ;
		// (6) check iss
		$isValidIss = $claims["iss"] == $server ? 1 : 0 ;
		$count = $isValidSignature + $isValidNbf + $isValidExp + $isValidSub + $isValidIss;
		$result = $count == 5 ? 1 : 0 ;
		return array($result, $isValidSignature, $isValidNbf, $isValidExp, $isValidSub, $isValidIss, $pwhash, $encodedData, $rawSig, $clientSig, $serverSig);
	}
	
	*/

}

?>
