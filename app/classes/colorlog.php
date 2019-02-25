<?php
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Colors\Color;

class ColorLog {
    /**
     * @var Logger
     */
    var $log_msg;
    var $log_err;
    var $c;

    /**
     * log constructor.
     */
    public function __construct(){
        //Include f3
        global $f3;
        
        //Add some color for CLI!
        $this->c = new Color();
        
        //Set line formatting
        $dateFormat = "m/d/y - h:i:s A";
        $output_msg = "%message%\n";
        $logging_msg = new LineFormatter($output_msg, $dateFormat);
        $output_err = "[%datetime%] %level_name% %message%\n";
        $logging_err = new LineFormatter($output_err, $dateFormat);

        //Setup logger
        $this->log_msg = new Logger("Logging_msg");
        $this->log_err = new Logger("Logging_err");
        
        //Add handler
        if($f3->get('debug_level')){
            $handler_msg = new StreamHandler("php://output", Logger::DEBUG);
            $handler_err = new StreamHandler("log/info.log", Logger::DEBUG);
        }else{
            $handler_msg = new StreamHandler("php://output", Logger::WARNING);
            $handler_err = new StreamHandler("log/info.log", Logger::INFO);
        }
        
        //Formatting
        $handler_msg->setFormatter($logging_msg);
        $handler_err->setFormatter($logging_err);
        
        //Push the handle
        $this->log_msg->pushHandler($handler_msg);
        $this->log_err->pushHandler($handler_err);
    }

    /**
     * Prints out information to the log
     *
     * @param $logMessage
     * @param $logData
     */
    public function log_info($logMessage, $logData = array()) {
        $logMessage = $this->c->apply('bold', $this->c->white($logMessage));
        $this->log_err->addInfo($logMessage);
    }

    /**
     * Prints out debug to the log
     *
     * @param $logMessage
     * @param $logData
     */
    public function log_debug($logMessage, $logData = array()) {
        //$logMessage = $this->c->apply('bold', $this->c->blue($logMessage));
        $this->log_err->addDebug($logMessage, $logData);
    }

    /**
     * Prints out warning to the log
     *
     * @param $logMessage
     * @param $logData
     */
    public function log_warn($logMessage, $logData = array()) {
        $logMessage = $this->c->apply('bold', $this->c->yellow($logMessage));
        $this->log_err->addWarning($logMessage, $logData);
    }

    /**
     * Prints out error to the log
     *
     * @param $logMessage
     * @param $logData
     */
    public function log_err($logMessage, $logData = array()) {
        $logMessage = $this->c->apply('bold', $this->c->red($logMessage));
        $this->log_err->addError($logMessage, $logData);
    }

    /**
     * Prints out notice to the log
     *
     * @param $logMessage
     * @param $logData
     */
    public function log_notice($logMessage, $logData = array()) {
        $logMessage = $this->c->apply('bold', $this->c->green($logMessage));
        $this->log_err->addNotice($logMessage, $logData);
    }

    /**
     * Prints out test message to the log
     *
     * @param $logMessage
     * @param $logData
     */
    public function log_test($logMessage, $logData = array()) {
        $logMessage = $this->c->apply('bold', $this->c->orange($logMessage));
        $this->log_err->addInfo($logMessage, $logData);
    }
    
    /**
     * Prints out chat messages to the log
     *
     * @param $logMessage
     */
    public function log_message($logMessage) {
        $logMessage = $this->c->apply('bold', $this->c->white($logMessage));
        $this->log_msg->addNotice($logMessage);
    }
}