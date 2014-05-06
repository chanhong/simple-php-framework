<?php

/*
 * By: Chanh Ong 
 * Date: 09/26/2013
 * Changes: Some bug fixes
 * DatabasePDO drop in replacement of the original Simple PHP Framework Database class 
 * 
  Notes: Please do the following to use this class

  1. rename original class.database class to class.databasemysql.php and class name to DatabaseMySQL

  2. add these lines to a new empty class.database.php

  // drop in replacement of the original Database Class with PDO class
  class Database extends DatabasePDO {
  }

 */

class DatabasePDO {

    // Singleton object. Leave $me alone.
    // for backward compatible
    private static $me;
    public $readDB;
    public $writeDB;
    public $readHost;
    public $writeHost;
    public $name;
    public $readUsername;
    public $writeUsername;
    public $readPassword;
    public $writePassword;
    public $onError; // Can be '', 'die', or 'redirect'
    public $emailOnError;
    public $queries;
    public $result;
    public $emailTo; // Where to send an error report
    public $emailSubject;
    public $errorUrl; // Where to redirect the user on error
    // for backward compatible
    // new in DatabasePDO
    public $DB;
    public $dbType;
    public $dbHost;
    public $dbUsername;
    public $dbPassword;

    // Singleton constructor
    private function __construct() {
        $this->dbType = Config::get('dbType');
        $this->name = Config::get('dbName');
        $this->onError = Config::get('dbOnError');
        $this->emailOnError = Config::get('dbEmailOnError');
        $this->queries = array(
        );
        if ($this->dbType == 'sqlite') {
            $this->DB = false;
        } else {
            // PDO specific stuff
            $this->dbHost = Config::get('dbReadHost');
            $this->dbUsername = Config::get('dbReadUsername');
            $this->dbPassword = Config::get('dbReadPassword');
            $this->DB = false;
        }
    }

    // Get Singleton object
    public static function getDatabase() {
        if (is_null(self::$me))
            self::$me = new Database();
        return self::$me;
    }

    // wrapper for backward compatible 
    public static function getInstance() {
        return self::getDatabase();
    }

    // wrapper for backward compatible 
    public function isReadConnected() {
        return is_resource($this->isConnected()) && get_resource_type($this->isConnected());
    }

    // wrapper for backward compatible 
    public function isWriteConnected() {
        return is_resource($this->isConnected()) && get_resource_type($this->isConnected());
    }

    public function __wakeup() {
        $this->Connect();
    }

    public function __sleep() {
        if (is_object($this->DB)) {
            $this->DB = null;
        }
        return array_keys(get_object_vars($this));
    }

    // Do we have a valid read/write database connection?
    public function isConnected() {
        return is_object($this->DB);
    }

    public function isSqlite() {
        if ($this->dbType == 'sqlite')
            return true;
        else
            return false;
    }

    // Do we have a valid database connection and have we selected a database?
    public function databaseSelected() {
        if (!$this->isConnected())
            return false;
        if ($this->isSqlite()) {
            $this->Connect();
            $result = $this->DB->query("SELECT name FROM sqlite_master WHERE type = 'table'");
        } else {
            $result = $this->DB->query("SHOW TABLES FROM $this->name");
        }
        return is_object($result);
    }

    public function Connect() {
        if ($this->isSqlite()) {
            $this->DB = new PDO("sqlite:" . DOC_ROOT . DS . "db" . DS . "$this->name.sqlite") or
                    $this->notify();
            if ($this->DB === false)
                return false;
            $this->DB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
        } else {
            $this->DB = new PDO("mysql:dbname=$this->name;host=$this->dbHost", $this->dbUsername, $this->dbPassword) or $this->notify();
            if ($this->DB === false)
                return false;
            $this->DB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
        }
        return $this->isConnected();
    }

    public function query($sql, $args_to_prepare = null, $exception_on_missing_args = true) {
        if (!$this->isConnected())
            $this->Connect();

        if (is_array($args_to_prepare)) {
            foreach ($args_to_prepare as $name => $val) {
                if (!is_int($val))
                    $val = $this->quote($val);
                $sql = str_replace(":$name:", $val, $sql, $count);
                if ($exception_on_missing_args && (0 == $count))
                    throw new Exception(":$name: was not found in prepared SQL query.");
            }
        }
        $this->queries[] = $sql;
        $this->result = $this->DB->prepare($sql) or $this->notify();
        if ($this->result)
            $this->result->execute($args_to_prepare);
        return $this->result;
    }

    public function showColumns($arg) {
        if ($this->isSqlite())
            return $this->query("PRAGMA table_info($arg);");
        else
            return $this->query("SHOW COLUMNS FROM $arg");
    }

    // Returns the number of rows.
    // You can pass in nothing, a string, or a db result
    public function numRows($arg = null) {
        $result = $this->resulter($arg);
        return ($result !== false) ? $result->rowCount() : false;
    }

// Returns the number of rows in the previously executed select statement
    public function rowCountResult() {
        if (!$this->isSqlite()) { // For SQLite only.
            return $this->result->rowCount(); // The last query was not a SELECT query. Return the row count                 }
        } else {
            if (strtoupper(substr($this->lastQuery(), 0, 6)) == 'SELECT') {
                // Do a SELECT COUNT(*) on the previously executed query
                $res = $this->query('SELECT COUNT(*)' . substr($this->lastQuery(), strpos(strtoupper($this->lastQuery()), 'FROM')))->fetch(PDO::FETCH_NUM);
                return $res[0];
            }
        }
    }

    // Returns true / false if the result has one or more rows
    public function hasRows($arg = null) {
        $result = $this->resulter($arg);
        return is_object($result) && ($this->rowCountResult() > 0);
    }

    // Returns the number of rows affected by the previous WRITE operation
    public function affectedRows() {
        if (!$this->isConnected())
            return false;
        $ret = $this->result->rowCount();
        return $ret;
    }

    // Returns the auto increment ID generated by the previous insert statement
    public function insertId() {
        if (!$this->isConnected())
            return false;
        $ret = ((is_null($this->DB->lastInsertId())) ? false : $this->DB->lastInsertId());
        return $ret;
    }

    // Returns a single value.
    // You can pass in nothing, a string, or a db result
    public function getValue($arg = null) {
        $result = $this->resulter($arg);
        return $this->hasRows($result) ? $result->fetchColumn() : false;
    }

    // Returns an array of the first value in each row.
    // You can pass in nothing, a string, or a db result
    public function getValues($arg = null) {
        $result = $this->resulter($arg);
        if (!$this->hasRows($result))
            return array(
            );

        $values = array(
        );
        $values = $result->fetchAll((PDO::FETCH_COLUMN | PDO::FETCH_GROUP), 0);
        return $values;
    }

    // Returns the first row.
    // You can pass in nothing, a string, or a db result
    public function getRow($arg = null) {
        $result = $this->resulter($arg);
        return $this->hasRows($result) ? $result->fetch(PDO::FETCH_ASSOC) : false;
    }

    // Returns an array of all the rows.
    // You can pass in nothing, a string, or a db result
    public function getRows($arg = null) {
        $result = $this->resulter($arg);
        if (!$this->hasRows($result))
            return array(
            );

        $rows = array(
        );
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    public function quote($var) {
        return "'" . $this->escape($var) . "'";
    }

    function escapeStr($inp) {
        if (is_array($inp))
            return array_map(__METHOD__, $inp);

        if (!empty($inp) && is_string($inp)) {
            return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp);
        }

        return $inp;
    }

    // Escapes a value.
    public function escape($var) {
        if (!$this->isConnected())
            $this->Connect();
        $ret = $this->escapeStr($var); // figure this out later
        return $ret;
    }

    public function numQueries() {
        return count($this->queries);
    }

    public function lastQuery() {
        if ($this->numQueries() > 0)
            return $this->queries[$this->numQueries() - 1];
        else
            return false;
    }

    private function notify() {
        if ($this->emailOnError === true) {
            $globals = print_r($GLOBALS, true);

            $msg = '';
            $msg .= "Url: " . full_url() . "\n";
            $msg .= "Date: " . dater() . "\n";
            $msg .= "Server: " . $_SERVER['SERVER_NAME'] . "\n";

            //$msg .= "ReadDB Error:\n" . ((is_object($this->readDB)) ? mysqli_error($this->readDB) : (($___mysqli_res = mysqli_connect_error()) ? $___mysqli_res : false)) . "\n\n";
            //$msg .= "WriteDB Error:\n" . ((is_object($this->writeDB)) ? mysqli_error($this->writeDB) : (($___mysqli_res = mysqli_connect_error()) ? $___mysqli_res : false)) . "\n\n";

            ob_start();
            debug_print_backtrace();
            $trace = ob_get_contents();
            ob_end_clean();

            $msg .= $trace . "\n\n";

            $msg .= $globals;

            mail($this->emailTo, $this->emailSubject, $msg);
        }

        if ($this->onError == 'die') {
//echo "<p style='border:5px solid red;background-color:#fff;padding:5px;'><strong>Read Database Error:</strong><br/>" . ((is_object($this->readDB)) ? mysqli_error($this->readDB) : (($___mysqli_res = mysqli_connect_error()) ? $___mysqli_res : false)) . "</p>";
//echo "<p style='border:5px solid red;background-color:#fff;padding:5px;'><strong>Write Database Error:</strong><br/>" . ((is_object($this->writeDB)) ? mysqli_error($this->writeDB) : (($___mysqli_res = mysqli_connect_error()) ? $___mysqli_res : false)) . "</p>";
//$this->readDB->errorInfo();
//$this->writeDB->errorInfo();
//print_r($this->result->errorInfo());
            echo '<br />';
            echo "<p style='border:5px solid red;background-color:#fff;padding:5px;'><strong>Last Query:</strong><br/>" . $this->lastQuery() . "</p>";
            echo "<pre>";
            debug_print_backtrace();
            echo "</pre>";
            exit;
        }

        if ($this->onError == 'redirect') {
            redirect($this->errorUrl);
        }
    }

    // Takes nothing, a MySQL result, or a query string and returns
    // the correspsonding MySQL result resource or false if none available.
    private function resulter($arg = null) {
        if (is_null($arg) && is_object($this->result))
            return $this->result;
        elseif (is_object($arg))
            return $arg;
        elseif (is_string($arg)) {
            $this->query($arg);
            if (is_object($this->result))
                return $this->result;
            else
                return false;
        } else
            return false;
    }

}

?>