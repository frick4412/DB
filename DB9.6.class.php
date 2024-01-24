<?php

// DB v9.6
//----------------------------------------------------------
// Created by Steven Crombie

/* CHANGES:
v9.6
  01/19/2024: Changed returned results back to object.  Added success field to object.  
		Errors return as object with success false.
		Declared $displayerrors in class to prevent php 8.2+ deprecation of dynamic properties notice.
v9.5 
  07/21/2023: Changed returned result from object with recs and count to simple array of objects as result.
v9.x
  03/27/2023: Removed extra surrounding array in returns statements so returns object, not array
  04/03/2023: Formatting to 4 space indent
v9.0
  07/08/2022: Removed query typ parameter and all responses returned with array of objects 
        Each object contains recs and count (insert returns id)
        Removed formatData function since all responses as array of objects
v7.1
  01/17/2019: Fixes to upsert code and insert.
        Changes to insert, update return values.
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
        $q["typ"]  = "qry"; // Version 9.0?
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
$typ = "qry"; // Version 9?
$sql = "SELECT TOP 100 * FROM tblLogins";
$par = array();
$res = $conn->query($typ, $sql, $par);


foreach($res as $r) {
  echo $r["Username"]."<br/>";
}
*/
/* EXAMPLE USAGE CALLING STORED PROCEDURE WITH MULTIPLE RECORD SETS
$sql = "EXEC ? = Emails_AddonException";
$par = array($ret);
$res = $conn->execsp($q);

print_r($res[0]);
print_r($res[1]);
print_r($res[2]);
*/

class DB {

    protected $_connection;
    protected $_config;
	protected $displayerrors;

    /*  Constructor
     *  1 parameter: connection file
     *  2 parameter: display errors text (true/false)
     */
    public function __construct($dbini, $displayerrors) {
        $this->_config = parse_ini_file($dbini,true);
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
		// Returns multiple result sets as an array of arrays of objects
        // $q is array ($q=>par must be array)
        $count = 0;
        try
        {
            $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
            $stmt->setFetchMode(PDO::FETCH_ASSOC); 
            $count = $stmt->execute($q["par"]);
        }
        catch (PDOException $e)
        { 
            if($this->displayerrors) {
                echo ('<p style="color:red;">Database query failed.<br/>');
                echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
                echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
                return "Fail!";
				$result = (object) array('success' => false, 'error' => $e->getCode().'<br/>'.$e->getMessage());
				return json_encode($result);
            }
        }
        if(!$count)
            return false; // no records
			$result = (object) array('success' => true, 'recs' => NULL, 'count' => 0);
    
        $recs = $stmt->fetchAll();
        $count = count($recs); // ??? COUNT OF RECORDSETS OR RECORDS
    
        $result = (object) array('success' => true, 'recs' => $recs, 'count' => $count);
        return $result;
        // $result = json_encode($recs);
    }

// ==============================================================	

// --------------------------------------------------------------------
    public function insert($q) {
        // Insert record and return count
        $res = 0;
        try
        {
            $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
            $stmt->execute($q["par"]);
            $id = $this->_getConn()->lastInsertId();
            $result = (object) array('success' => true, 'id' => $id);
            return json_encode($result);
            //return $id;  // returns id of inserted record or false
        }
        catch (PDOException $e)
        {
            if($this->displayerrors) {
                // echo ('<p style="color:red;">Database insert failed.<br/>');
                // echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
                // echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
				$result = (object) array('success' => false, 'error' => $e->getCode().'<br/>'.$e->getMessage());
				return json_encode($result);
            }
        }
    }

// --------------------------------------------------------------------
    function update ($q) {
        // Update record and return operation and count
        try
        {
            $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
            $stmt->execute($q["par"]);
            $cnt = $stmt->rowCount();
            //return $cnt;  // returns number of rows affected
            $result = (object) array('success' => true, 'count' => $cnt);
            return json_encode($result);
        }
        catch (PDOException $e)
        {
            if($this->displayerrors) {
                // echo ('<p style="color:red;">Database update failed.<br/>');
                // echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
                // echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
				$result = (object) array('success' => false, 'error' => $e->getCode().'<br/>'.$e->getMessage());
				return json_encode($result);
            }
        }
    }

// --------------------------------------------------------------------
  public function upsert ($q) {  // $upd_sql, $upd_par, $ins_sql, $ins_par
    // TODO: Add try/catch blocks here
    $upd = $this->update(array("sql" => $q["upd_sql"], "par" => $q["upd_par"]));
    if($upd == false) { // Perform insert if no rows updated
      $ins = $this->insert(array("sql" => $q["ins_sql"], "par" => $q["ins_par"]));
      $result = (object) array('success' => true, 'op' => 'insert', 'count' => $ins);
      return json_encode($result);
      //$ret = array("insert", $ins);  // return array including id
    } else {
      $result = (object) array('success' => true, 'op' => 'update', 'count' => $upd);
      return json_encode($result);
      //$ret = array("update", $upd);  // return array including count
    }
    //return $ret;
  }

// --------------------------------------------------------------------
    public function delete ($q) {
        // Delete record and return count
        $del = 0;
        try
        {
          $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
          $del = $stmt->execute($q["par"]);
        }
        catch (PDOException $e)
        {
          if($this->displayerrors) {
            // echo ('<p style="color:red;">Database query failed.<br/>');
            // echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            // echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
			$result = (object) array('success' => false, 'error' => $e->getCode().'<br/>'.$e->getMessage());
			return json_encode($result);
          }
        }
        if($del !== false) {
          $count = $stmt->rowCount();
		  $result = (object) array('success' => true, 'count' => $count);
          return json_encode($result);  // returns number of rows affected
        }
    }
    
    public function execsp($q) {
        // Execute stored procedure
        $count = 0;
        try
        {
          $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
          $stmt->setFetchMode(PDO::FETCH_ASSOC); 
          $count = $stmt->execute($q["par"]);
        }
        catch (PDOException $e)
        {
          if($this->displayerrors) {
            // echo ('<p style="color:red;">Database query failed.<br/>');
            // echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            // echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
            // return "Fail!";
			$result = (object) array('success' => false, 'error' => $e->getCode().'<br/>'.$e->getMessage());
			return json_encode($result);
          }
        }        
        // NOT ALL SPs RETURN record sets (use 'none' for this case)
        if(!$count)
            return false;
     
		// get all record sets as array with recs and count
		$data = array();
		do {
		  $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
		  if ($rs) {
		  $count = count($rs);
		  $data[] = array("recs" => $rs, "count" => $count);
		  }
		} while (
		  $stmt->nextRowset()
		);	

		return json_encode($data);
    }
  
    /**
     * Authenticate against Active Directory
     *
     * Authenticate against Active Directory
     *
     * @return int Authenticated?
     */
    public function auth_ad($user, $pass) {
      // *** Don't transfer passwords clear-text. ***
        // ldap extension must be enabled on server
        if (substr($user, 0, 8) != "FARMERS\\") {
            $user = "FARMERS\\" . $user;
        }
        $server = "farmers.intranet";
        $ldap = @ldap_connect($server);

        if (@ldap_bind($ldap, $user, $pass)) {
            ldap_unbind($ldap);
            $auth = true;
        } else {
            $auth = false;
        }
        if (strlen($pass) == 0) {
            $auth = false;
        }
        return $auth;
    }
    
    public function auth_msint() {  // returns DOMAIN login for sites without anonymous access and with MS integrated security enabled 
      return $_SERVER['REMOTE_USER'];  // has domain prefix of "FARMERS\"
    }

// ----------------------------------------------------------------------------------

	/*
    public function obj($q) {
        // SQL SHOULD RETURN ONLY ONE ROW (USE TOP 1)
        $stmt = $this->_getConn()->prepare($q["sql"]);  // Use lazy loading getter
        $params = $stmt->execute($q["par"]);
        $stmt->setFetchMode(PDO::FETCH_OBJECT);  // fetch_object instead of fetch_assoc
        $data = $stmt->fetch();
        return $data;
    }
	*/

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
  
  // jsonp encode data if callback defined in URL	else json encode data
    // supports both json and jsonp
    // see example at beginning of file
  // public function formatData($data) {
    // $callback = isset($_GET["callback"]) ? $_GET["callback"] : NULL ;
    // $data = json_encode($data);
    // if($callback) {
      // return $callback."(".$data.")";
    // } else {
      // return $data;
    // }
  // }
  
}

?>
