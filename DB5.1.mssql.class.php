<?php

/*
$conn = new DB("Intranet.rw.ini");
$typ = "qry";

$sql = "SELECT TOP 100 * FROM tblLogins";
$par = array();
$res = $conn->query($typ, $sql, $par);


foreach($res as $r) {
	echo $r["Username"]."<br/>";
}
*/
//----------------------------------------------------------
// Created by Steven Crombie

/* CHANGES:
	08/24/2016: Added formatData() to call json_encode and wrap in callback if present
	            Added PDO:SQLSRV_ATTR_QUERY_TIMEOUT to _getConn()
				Deprecated queryPage(), recordCount(), and pageCount().
				Removed version from class name.  Now just DB.
*/

/*  NOT USED
function __autoload ($class) {
    // Filename should be same as class name with .class.php double extension
    // No changes required
    require_once ($class.'.class.php');
}
*/

/* EXAMPLE USAGE
$app->get('/test',
    function() use($app) {
        $cnn = new DB5(CONN);  // CONN is defined constant of .ini filename
        $typ  = "qry";
        $sql = "SELECT Id, Text, Memo FROM Test ORDER BY Id";
        $par = array();
        $res = $cnn->query($typ, $sql, $par);
		echo $cnn->formatData($res);
        $cnn = null;
    }
);
*/

class DB {

    protected $_connection;
    protected $_config;

    public function __construct($dbini) {
        $this->_config = parse_ini_file($dbini,true);
        // Reference Example: $this->_config['db']['drvr']
    }

    public function query($typ, $sql, $par) {  
        // $par must be an array
        $count = 0;
        try
        {
        $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
        $count = $stmt->execute($par);
        }
        catch (PDOException $e)
        {
            echo ('<p style="color:red;">Database query failed.<br/>');
            echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
			return "Fail!";
        }
        if(!$count)
            return false;

        if($typ <> "get")
            $stmt->setFetchMode(PDO::FETCH_ASSOC);

        switch($typ) {
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

        return $data;
    }

// ==============================================================	
// Functions queryPage(), recordCount(), and pageCount() deprecated. [SC]
// Two reasons: new SQL Server versions have LIMIT capability and 
// all records should be returned and paged using javascript.
// ==============================================================	
	
    /*
     * Returns a page of records
     *
     * Returns a page of records
     *
     * @param array sql : Values for col, frm, whr, ord
     * @param array par : Parameter values for replacement in sql statement
     * @param int cur : Current page number
     * $param int siz : Page size
     * @return array Array of array with records and record field values
     */

    public function queryPage($sql, $par, $cur, $siz) {
        // $sql["ord"] is required to have a value
	$sql = "WITH records AS (
			SELECT ROW_NUMBER() OVER(ORDER BY ".$sql['ord'].") AS RowNum, 
				".$sql['col']." 
				from ".$sql['frm']." 
				where ".$sql['whr']."
			)
			SELECT * FROM records
			WHERE RowNum BETWEEN ((".$cur." - 1) * ".$siz.") + 1 AND ".$cur." * ".$siz;
        $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
        $params = $stmt->execute($par);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        $data = $stmt->fetchAll();

        return $data;
    }
    
    /*
     * Returns record count
     *
     * Returns record count particularly for use with paging queries to
     * determine the total number of pages
     *
     * @param array $sql :col, frm, whr, ord
     * @param array $par with all values to be replaced in sql
     * @return int
     */
    public function recordCount($sql, $par) {
        // Use 'DISTINCT' before columns for unique rows
        $sql = "SELECT COUNT(".$sql['col'].") AS cnt from ".$sql['frm']." where ".$sql['whr'];
        $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
        $params = $stmt->execute($par);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        $data = $stmt->fetch();
        $data = $data['cnt'];
        return $data;
    }

    public function pageCount($sql, $par, $siz) {
        // Use 'DISTINCT' before columns for unique rows
	$sql = "SELECT COUNT(".$sql['col'].") AS cnt from ".$sql['frm']." where ".$sql['whr'];
        $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
        $params = $stmt->execute($par);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        $data = $stmt->fetch();
        $data = $data['cnt'];
        $pc   = intval($data / $siz);
        $data = $data % $siz ? $pc + 1 : $pc ;
        return $data;
    }
// ==============================================================	

// --------------------------------------------------------------------
    public function insert($sql, $par) {
        // Insert record
        $ins = 0;
        try
        {
            $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
            $ins = $stmt->execute($par);
        }
        catch (PDOException $e)
        {
            echo ('<p style="color:red;">Database query failed.<br/>');
            echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
        }
        if(!$ins)
            return false;

        // Get inserted id  (NULL or 0 if no auto-increment field or manually populated)
		$data = $this->query("get","SELECT @@IDENTITY", array());
        return $data;
    }

// --------------------------------------------------------------------
    function update ($sql, $par) {
        // Update record
        $upd = 0;
        try
        {
            $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
            $upd = $stmt->execute($par);
        }
        catch (PDOException $e)
        {
            echo ('<p style="color:red;">Database query failed.<br/>');
            echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
        }
        if(!$upd)
            return false;

        // Get affected rows
		$data = $this->query("get","SELECT @@RowCount", array());
		return $data;
    }

// --------------------------------------------------------------------
	public function upsert ($upd_sql, $upd_par, $ins_sql, $ins_par) {
		$upd = $this->update($upd_sql, $upd_par);
		if(!$upd) {
			$ins = $this->insert($ins_sql, $ins_par);
			$ret = "i".$ins;
		} else {
			$ret = "u".$upd;
		}
		return $ret;
	}

// --------------------------------------------------------------------
    public function delete ($sql, $par) {
        // Delete record
        $del = 0;
        try
        {
            $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
            $del = $stmt->execute($par);
        }
        catch (PDOException $e)
        {
            echo ('<p style="color:red;">Database query failed.<br/>');
            echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
        }
        if(!$del)
            return false;

        // Get affected rows
		$data = $this->query("get","SELECT @@RowCount", array());
        return $data;
    }
    
    public function execsp($typ,$sql,$par) {
        // Execute stored procedure
        $count = 0;
        try
        {
        $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
        $count = $stmt->execute($par);
        }
        catch (PDOException $e)
        {
            echo ('<p style="color:red;">Database query failed.<br/>');
            echo ('&nbsp;&nbsp;getCode: '.$e->getCode().'<br/>');
            echo ('&nbsp;&nbsp;getMessage: '.$e->getMessage().'</p>');
			return "Fail!";
        }
        
        // NOT ALL SPs RETURN record sets
        if(!$count)
            return false;

        switch($typ) {
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
                $data = $stmt->fetchAll(); 
        }
        
        return $data;
            
    }
	
	public function formatData($data) {
		$callback = isset($_GET["callback"]) ? $_GET["callback"] : NULL ;
		$data = json_encode($data);
		if($callback) {
			return $callback."(".$data.")";
		} else {
			return $data;
		}
	}

// ----------------------------------------------------------------------------------

    public function obj($sql,$par) {
        // sql should return only one row
        $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
        $params = $stmt->execute($par);
        $stmt->setFetchMode(PDO::FETCH_OBJECT);  // fetch_object instead of fetch_assoc

        $data = $stmt->fetch();

        return $data;
    }

    protected function _getConn() {
        // Lazy load connection
        $dsn = "";
        if($this->_connection === null) {
			$dsn = "dblib:version:7.0;charset=UTF-8;host=".$this->_config['db']['host'].";dbname=".$this->_config['db']['db'].";"; // working	
            try {
                $this->_connection = new PDO($dsn, $this->_config['db']['usr'], $this->_config['db']['pwd']);
                $this->_connection -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$cmd = $this->_connection->prepare('SET ANSI_WARNINGS ON');
		$cmd->execute();
		$cmd = $this->_connectiono->prepare('SET ANSI_NULL ON');
		$cmd->execute();
            }
            catch(PDOException $e) {
                print "Connection to database failed!".$e;
                die();
            }
        }
        return $this->_connection;
    }
}

?>
