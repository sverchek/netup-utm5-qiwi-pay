<?php
header("Content-type: text/xml");

$TEMPLATE["XML_CHECK"] = '<?xml version="1.0" encoding="UTF-8"?><response><osmp_txn_id>[OSMP_TXN_ID]</osmp_txn_id><result>[RESULT]</result><comment>[COMMENT]</comment></response>';

$TEMPLATE["XML_PAY"] = '<?xml version="1.0" encoding="UTF-8"?><response><osmp_txn_id>[OSMP_TXN_ID]</osmp_txn_id><prv_txn>[PRV_TXN]</prv_txn><sum>[SUM]</sum><result>[RESULT]</result><comment>[COMMENT]</comment></response>';

$mysql_hostname = "localhost";
$mysql_user = "DB_USER";
$mysql_password = "DB_PASS";
$mysql_database = "DB_NAME";
$bd = mysqli_connect($mysql_hostname, $mysql_user, $mysql_password, $mysql_database);

$datenow = date("d:m:y G:i:s");
//Лог файл
function fileopen($lines)
{
    $dir = dirname(dirname(__FILE__));
    $date_file = date("Y-m-d"); //дата файла
    $name_file = $date_file . "-qiwi.txt"; //имя файла
    $file = $dir . "/DIR/log/" . $name_file;    //куда пишем логи
    file_put_contents($file, $lines, FILE_APPEND);
}


$command = $_GET["command"];

//проверка пользователя
if ($command == "check") {
    if (!$bd) {
        $result = "1";
        $comment = "Database not accessed";
        fileopen("$datenow Database not accessed\n");
    } else {
        $txn_id = $_GET["txn_id"];
        $account = $_GET["account"];
        $sum = $_GET["sum"];

        if (preg_match("/^[0-9]{1,20}$/", $txn_id)) {
            if (preg_match("/^[0-9]{1,20}$/", $account)) {
                if (preg_match("/^[0-9]{1,6}[.][0-9]{1,6}$/", $sum)) {
                    $sql = "SELECT id, balance, credit, is_blocked 
                            FROM  accounts 
                            WHERE id = ".$account." and is_deleted!=1";

                    $data = mysqli_query($bd, $sql);
                    $data = mysqli_fetch_assoc($data);
                    $acc_id = $data["id"];
                    $balance = $data["balance"];
                    $credit = $data["credit"];
                    $is_blocked = $data["is_blocked"];

                    if ($acc_id == "") {
                        $result = "5";
                        $comment = "Personal account not found";
                        fileopen("$datenow Account $account not found!\n");
                    }

                    if ($acc_id != "") {
                        $result = "0";
                        $comment = "Personal account found";
                        fileopen("$datenow Account $account is found!\n");
                    }


                } else {
                    $result = "300";
                    $comment = "Incorrent format of sum";
                    fileopen("$datenow Incorrent format of sum\n");
                }
            } else {
                $result = "4";
                $comment = "Incorrent format of account";
                fileopen("$datenow Incorrent format of account\n");
            }
        } else {
            $result = "300";
            $comment = "Incorrent format of txn_id";
            fileopen("$datenow Incorrent format of txn_id\n");
        }
    }
}

//оплата
if ($command == "pay") {
    if (!$bd) {
        $result = "1";
        $comment = "Database not accessed";
        fileopen("$datenow Database not accessed\n");
    } else {
        $txn_id = $_GET["txn_id"];
        $txn_date = $_GET["txn_date"];
        $account = $_GET["account"];
        $sum = $_GET["sum"];

        if (preg_match("/^[0-9]{1,20}$/", $txn_id)) {
            if (preg_match("/^[0-9]{1,20}$/", $account)) {
                if (preg_match("/^[0-9]{1,6}[.][0-9]{1,6}$/", $sum)) {
                    if (preg_match("/^([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})$/", $txn_date, $regs)) {

                        
						$year = $regs[1];
                        $month = $regs[2];
                        $day = $regs[3];
                        $hour = $regs[4]+2;//+2 Часовой пояс 
                        $min = $regs[5];
                        $sec = $regs[6];
                        $bdate = mktime($hour, $min, $sec, $month, $day, $year);

                        $txnidchk = "qiwi-".$txn_id;
                        $sqlchk = '
                        SELECT payment_ext_number, is_canceled
                        FROM  payment_transactions
                        WHERE payment_ext_number = \''.$txnidchk.'\' and is_canceled != 1 LIMIT 1';

                        $datachk = mysqli_query($bd, $sqlchk);
                        $datachk = mysqli_fetch_assoc($datachk);
                        $payment_ext_number = $datachk["payment_ext_number"];
                        $payment_ext_number = str_replace("qiwi-", "", $payment_ext_number);


                        if ($payment_ext_number != $txn_id) {

                            $eedata = "qiwi-".$txn_id;
							//оплата чеез приложение нетап
                            exec("/netup/utm5/bin/utm5_payment_tool -h 127.0.0.1 -P 11758 -m 100 -b " . $sum . " -a " . $account ." -L 'from qiwi' -k 'from qiwi' -e " . $eedata . " -t " . $bdate . " -i");

                            $sqlchk2 = '
                            SELECT payment_ext_number, is_canceled
                            FROM  payment_transactions
                            WHERE payment_ext_number = \''.$eedata.'\' and is_canceled != 1 LIMIT 1';
                            $datachk2 = mysqli_query($bd, $sqlchk2);
                            $datachk2 = mysqli_fetch_assoc($datachk2);
                            $payment_ext_number2 = $datachk2["payment_ext_number"];
                            
                            fileopen("payment " . $eedata . " " . $bdate . " " . $account . " " . $sum);

                            if ($payment_ext_number2 == $eedata) {
                                $eedata = "qiwi-".$txn_id;
                                $sqloperid = '
                                SELECT id,payment_ext_number,is_canceled
                                FROM  payment_transactions
                                WHERE payment_ext_number = \''.$eedata.'\' and is_canceled != 1 LIMIT 1';
                                $dataoperid = mysqli_query($bd, $sqloperid);
                                $dataoperid = mysqli_fetch_assoc($dataoperid);
                                $prv_txn = $dataoperid["id"];
                                $result = "0";
                                $comment = "Payment recieved";
                                fileopen("$datenow Payment for $account recieved $sum roubles\n");
                            } elseif ($payment_ext_number2 != $txn_id) {
                                $result = "5";
                                $comment = "Payment account not found";
                                fileopen("account not found " . $eedata . " " . $bdate . " " . $account . " " . $sum . "\n");
                            }

                        } elseif ($payment_ext_number == $txn_id) {
                            $eedata = "qiwi-".$txn_id;
                            $sqloperid = '
                            SELECT id,payment_ext_number,is_canceled
                            FROM  payment_transactions
                            WHERE payment_ext_number = \''.$eedata.'\' and is_canceled != 1 LIMIT 1';
                            $dataoperid = mysqli_query($bd, $sqloperid);
                            $dataoperid = mysqli_fetch_assoc($dataoperid);
                            $prv_txn = $dataoperid["id"];
                            $result = "0";
                            $comment = "Payment with this ".$txn_id." alredy recieved";
                            fileopen("$datenow Payment with this ".$txn_id." alredy recieved\n");
                        }

                    } else {
                        $result = "300";
                        $comment = "Incorrent format of txn_date";
                        fileopen("$datenow Incorrent format of txn_date\n");
                    }
                } else {
                    $result = "300";
                    $comment = "Incorrent format of sum";
                    fileopen("$datenow Incorrent format of sum\n");
                }
            } else {
                $result = "4";
                $comment = "Incorrent format of account";
                fileopen("$datenow Incorrent format of account\n");
            }
        } else {
            $result = "300";
            $comment = "Incorrent format of txn_id";
            fileopen("$datenow Incorrent format of txn_id\n");
        }
    }
}

//нет команды
if($command != "check" && $command != "pay"){
    $result="300"; $comment="Incorrect command request";
    fileopen("$datenow Incorrect request\n");
}
//проверка
if ($command == "check"){
    $replace = array("[RESULT]" => $result, "[OSMP_TXN_ID]" => $txn_id, "[COMMENT]" => $comment);
    echo strtr($TEMPLATE["XML_CHECK"],$replace);
}

//оплата
if ($command == "pay"){
    $replace = array("[RESULT]" => $result, "[OSMP_TXN_ID]" => $txn_id, "[COMMENT]" => $comment, "[PRV_TXN]" => $prv_txn, "[SUM]" => $sum);
    echo strtr($TEMPLATE["XML_PAY"],$replace);
}

//неизвестная ошибка
if ($command != "pay" && $command != "check"){
    $replace = array("[RESULT]" => $result, "[OSMP_TXN_ID]" => $txn_id, "[COMMENT]" => $comment);
    echo strtr($TEMPLATE["XML_CHECK"],$replace);
}

?>