<?php

// Created by Steven Crombie

/* CHANGES:
 * v4.2
 * 09/01/2016: Added functions for JWT reading and validation
 *
 * v4.1
 * 08/30/2016: Commented out queryPage(), recordCount(), pageCount()
 * 			   Added formatData() to call json_encode and wrap in callback, if present.
 *			   Removed version from class name.  Now just DB.
 *			   Added example usage in header.
 */

// Notes:
//   Application must define $_SESSION['dbparams'] as .ini file with connection parameters

/* EXAMPLE USAGE
 * 		$app->get('/test',
 *		  function() use($app) {
 *		    $cnn = bnew DB(CONN);  // CONN is defined constant of .ini filename
 *			$typ = "qry";
 *			$sql = "SELECT Id, Text, Memo FROM Test ORDER BY Id";
 *			$par = array();
 *			$res = $cnn->query($typ, $sql, $par);
 *			echo $cnn->formatData($res);
 *			$cnn = null;
 *		  }
 *		);
 */

/* ---- NOT USED ----
function __autoload ($class) {
    // Filename should be same as class name with .class.php double extension
    // No changes required
    require_once ($class.'.class.php');
}
*/

class DB {

    protected $_connection;
    protected $_config;

    public function __construct($dbini) {
        $this->_config = parse_ini_file($dbini,true);
        // Reference Example: $this->_config['db']['drvr']
    }

    public function test() {
        echo "Test successful.<br/>";
        echo $this->_config['db']['host'];
        echo "<br />";
        echo $this->_config['db']['db'];
        echo "<br />";
        echo $this->_config['db']['usr'];
        echo "<br />";
        echo $this->_config['db']['pwd'];
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
            case "sel":  // also for updates
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

    /*
     * Returns a page of records
     *
     * @param array sql : Values making sql depending on mysql or sqlsrv
     *                    sqlsrv: col, frm, whr, ord
     *                    mysql : sql
     * @param array par : Parameter values for replacement in sql statement
     * @param int cur : Current page number
     * $param int siz : Page size
     * @return array Array of array with records and record field values
     */

	/*
    public function queryPage($sql, $par, $cur, $siz) {

        // Untested as of 4/18
        $first = (intval($cur)-1)*$siz;
        $sql = $sql['sql'];
        $sql = $sql." LIMIT ".$first.",".$pgSz;

        $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
        $params = $stmt->execute($par);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        $data = $stmt->fetchAll();

        return $data;
    }
	*/
   
    /*
     * Returns record count
     *
     * Returns record count particularly for use with paging queries to
     * determine the total number of pages
     *
     * @param array $sql Values making sql: col, frm, whr, ord
     * @param array $par with all values to be replaced in sql
     * @return int
     */
	/*
    public function recordCount($sql, $par) {
       
        $sql = "SELECT COUNT(".$sql['col'].") AS cnt from ".$sql['frm']." where ".$sql['whr'];

        $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
        $params = $stmt->execute($par);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        $data = $stmt->fetch();
        $data = $data['cnt'];
       
        return $data;
    }
	*/
	
	/*
    public function pageCount($sql, $par, $siz) {

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
	*/

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
        $data = $this->_getConn()->lastInsertId($ins);
        return $data;
    }

// --------------------------------------------------------------------
    public function update ($sql, $par) {
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
        $count = $this->_getConn()->rowCount($upd);
        //$data = $data[0];

        return $count;

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
        //$data = $this->_getConn()->rowCount();
        //$data = $data[0];
        $cnt = $stmt->rowCount();  // Always returns 1 if successful, not a true count

        return $cnt;
    }

// ----------------------------------------------------------------------------------
	public function formatData($data) {
		$data = json_encode($data);
		if(isset($_GET["callback"])) {
			return $_GET["callback"]."(".$data.")";
		} else {
			return $data;
		}
	}

// ----------------------------------------------------------------------------------

    public function obj($sql,$par) {
        // sql should return only one row
        $stmt = $this->_getConn()->prepare($sql);  // Use lazy loading getter
        $params = $stmt->execute($par);
        $stmt->setFetchMode(PDO::FETCH_OBJECT);

        $data = $stmt->fetch();

        return $data;
    }

    protected function _getConn() {
        // Lazy load connection
        $dsn = "";
        if($this->_connection === null) {
            $dsn = "mysql:host=".$this->_config['db']['host'].";dbname=".$this->_config['db']['db'];
            try {
                $this->_connection = new PDO($dsn, $this->_config['db']['usr'], $this->_config['db']['pwd']);
                $this->_connection -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
            catch(PDOException $e) {
                print "Connection to database failed!";
                die();
            }
        }
        return $this->_connection;
    }
}

?>
