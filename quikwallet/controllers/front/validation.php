<?php

class QuikwalletValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        global $smarty;

        //Logger::addLog("QW QuikwalletValidationModuleFrontController reached ");

        //if form is submit through quikwallet form on front end
        if (isset($_POST["quikwalletsubmit"])) {

            $table = _DB_PREFIX_ . 'quik_pay';

            // Getting data to build url
            $partnerid =  Configuration::get('QUIKWALLET_PARTNER_ID')  ;

            // Url to call
            $url       = Configuration::get('QUIKWALLET_URL') . "/" .$partnerid . "/requestpayment";
            $secret    = Configuration::get('QUIKWALLET_SECRET');
            //$partnerurl = $this->settings['partnerurl'];

            /*
             * force partnerurl to checkout url. Currently payment response only working on view
             * cart page
             */

            //$partnerurl = $woocommerce->cart->get_cart_url();

            //$partnerurl = site_url();

            $mobile  = $_POST["phone"];
            $amount  = $_POST["amount"];
            $name    = $_POST["firstname"];
            $email   = $_POST["quik_email"];
            $address = $_POST["address1"] . ", " . $_POST["address2"];
            $city    = $_POST["city"];
            $pincode = $_POST["zipcode"];
            $orderid = $_POST["order_id"];
            $date_c  = date('Y-m-d H:i');

            //Logger::addLog("QW QuikwalletValidationModuleFrontController amount from POST call is  ".$amount);


            $partnerurl = $smarty->tpl_vars['base_dir_ssl']->value."?fc=module&module=quikwallet&controller=validation";


            /*
             * Record order details
             *
             */

            $escape_email =  Db::getInstance()->escape($email) ;//$this->db->escape($email);
            $escape_date = Db::getInstance()->escape($date_c);//$this->db->escape($date_c);
            $escape_address = Db::getInstance()->escape($address);//$this->db->escape($address);

            $sql = "REPLACE INTO `$table` (
                `order_no` ,
                `date_c` ,
                `name`,
                `email_id`,
                `address`,
                `city` ,
                `pincode` ,
                `mobile`,
                `amount` ,
                `q_id`,
                `hash`,
                `checksum`,
                `order_status`)
                VALUES(
                    '$orderid',
                    '$escape_date',
                    '$name',
                    '$escape_email',
                    '$escape_address',
                    '$city',
                    '$pincode',
                    '$mobile',
                    '$amount',
                    '','','','') ";

            //$this->log->debug("MYSQL QUERY" , $sql);

            Db::getInstance()->execute($sql);


            $postFields = Array(
                "partnerid" => $partnerid, //fixed
                "secret" => $secret, //fixed
                //"outletid" => "39", //fixed - only for restaurant
                "redirecturl" => "$partnerurl" . "", //fixed
                "mobile" => $mobile, //client mobile no
                "billnumbers" => $orderid, //unique order no in the system
                "email" => $email, //unique order no in the system
                "amount" => $amount //amount for the transaction
            );


            //$this->log->debug("Post fields are " , $postFields);
            // AJAX call starts
            // Building post data
            $postFields = http_build_query($postFields);

            //$this->log->debug("POST url is  " , $url);

            //$this->log->debug(" POST postfields build query are " , $postFields);


            //cURL Request
            $ch = curl_init();

            //set the url, number of POST vars, POST data

            // defaults setting
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch,CURLOPT_HEADER,false);
            curl_setopt($ch,CURLOPT_ENCODING,'gzip,deflate');
            curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,15);
            curl_setopt($ch,CURLOPT_TIMEOUT,30);
            curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
            curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);

            // contextual info apart from defaults
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

            $info = curl_getinfo($ch);

            //$this->log->debug("FULL CH IS ",$info);


            $this->response = curl_exec($ch);

            $info = curl_getinfo($ch);

            //$this->log->debug("FULL CH IS ",$info);

            if ($this->response === false) {
                $this->response = curl_error($ch);
            }

            // Fetching response
            $resp = $this->response;

            //$this->log->debug("Response was " , $resp);


            // Decode
            $r = json_decode($resp, true);



            if ($r['status'] == 'failed') {
                $message = $r['message'];

            } else if ($r['status'] == 'success') {
                $id     = $r['data']['id'];
                $hash   = $r['data']['hash'];
                $newurl = $r['data']['url'];


                //$this->log->debug("JSON RESPONSE --> ",$r['data']);

                $id2 = substr($id, 2);

                $escape_q_id =  Db::getInstance()->escape($id2);//$this->db->escape($id2);
                $escape_hash =  Db::getInstance()->escape($hash);//$this->db->escape($hash);

                // post API DB part

                $sql = " UPDATE `$table`  SET `q_id` = '$escape_q_id' , `hash` = '$escape_hash' WHERE
                    `order_no` = '$orderid' ";

                //$this->log->debug("MYSQL UPDATE QUERY" , $sql);

                Db::getInstance()->execute($sql);

                //$this->response->redirect($newurl);

                header("Location: " . $newurl);


                // below redirecting to quikwallet payment gateway after updating q_id and hash
                // e.g $newurl = https://app.quikpay.in/#paymentrequest/6uP0SoY

                //header("Location: " . $newurl);
            } else {
                //print "Invalid Response";
            }

            // Exit Strategy
            exit();

        }

        /*
         * After redirection from payment gateway to our site update quik_pay
         */

        if (isset($_GET['status']) && isset($_GET['id']) && isset($_GET['checksum'])) {

            $table = _DB_PREFIX_ . 'quik_pay';



            $status   = $_GET["status"];
            $id       = $_GET["id"];
            $checksum = $_GET["checksum"];

            $sql = "SELECT * FROM `$table` WHERE
                `q_id` = '$id' ";

            $result =  Db::getInstance()->getRow($sql);

            //Logger::addLog(" select query result was ".$result["order_no"]." and q_id was ".$result["q_id"]);

            $order_id = $result["order_no"];

            $partnerid =  Configuration::get('QUIKWALLET_PARTNER_ID')  ;
            $secret =  Configuration::get('QUIKWALLET_SECRET')  ;

            $text = "status=$status&id=$id&billnumbers=$order_id";
            $hmac = hash_hmac('sha256', $text, $secret);

            //$order_info = $this->model_checkout_order->getOrder($order_id);

            //Logger::addLog("text was ".$text. " and secret was ".$secret);

            //Logger::addLog("QW hashmac checking hashmac " .$hmac. "   and checksum was ".$checksum);

            $cart_id  = $order_id;
            $cart = new Cart($cart_id);
            $customer = new Customer($cart->id_customer);
            $quikwallet = new QuikWallet();
            $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

            if ($hmac == $checksum) {

                $escape_order_status =  Db::getInstance()->escape($status) ;//$this->db->escape($status);
                $escape_checksum = Db::getInstance()->escape($checksum) ;//$this->db->escape($checksum);
                $escape_q_id = Db::getInstance()->escape($id) ;//$this->db->escape($id);

                // post API DB part

                $sql = "UPDATE `$table`  SET `order_status` = '$escape_order_status' , `checksum` = '$escape_checksum' WHERE
                    `q_id` = '$escape_q_id' ";

                Db::getInstance()->execute($sql);

                //Logger::addLog("QW hashmac matched DONE!!!");

                if ($order_id != '') {

                    try{

                        $status = strtolower($status);

                        if($status == "paid"){

                            $history_message = "Payment Successful for Order# ".$cart_id.". QuikWallet payment id:".$id ;

                            // success
                            $quikwallet->validateOrder($cart_id, 2, $total, $quikwallet->displayName, $history_message, array(), NULL, false, $customer->secure_key);

                            Logger::addLog($history_message, 1);

                            $query = http_build_query(array(
                                'controller'    =>  'order-confirmation',
                                'id_cart'       =>  (int) $cart->id,
                                'id_module'     =>  (int) $this->module->id,
                                'id_order'      =>  $quikwallet->currentOrder
                            ), '', '&');

                            $url = 'index.php?' . $query;

                            Tools::redirect($url);

                        }else{

                            $history_message = "Transaction ERROR for Order# ".$cart_id.". QuikWallet payment id: ".$id ;

                            // error payment
                            $quikwallet->validateOrder($cart_id, 8, $total, $quikwallet->displayName, $history_message, array(), NULL, false, $customer->secure_key);

                            Logger::addLog($history_message, 1);

                            $status_code = "Failed";

                            $this->context->smarty->assign(array(
                                'status' => $status_code,
                                'responseMsg' => $history_message,
                                'this_path' => $this->module->getPathUri(),
                                'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->module->name.'/'
                            ));

                            $cart->delete();
                            $this->setTemplate('payment_response.tpl');

                        }

                    }catch(Exception $e){

                        $history_message = "Transaction ERROR for Order#".$cart_id.". QuikWallet payment id:".$id ;

                        // error payment
                        $quikwallet->validateOrder($cart_id, 8, $total, $quikwallet->displayName, $history_message, array(), NULL, false, $customer->secure_key);

                        Logger::addLog($history_message, 1);

                        $status_code = "Failed";

                        $this->context->smarty->assign(array(
                            'status' => $status_code,
                            'responseMsg' => $history_message,
                            'this_path' => $this->module->getPathUri(),
                            'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->module->name.'/'
                        ));

                        $cart->delete();
                        $this->setTemplate('payment_response.tpl');


                    }

                }

            }
            else
            {

                $history_message = "Your Order# ". $cart_id. " was not completed due to SECURITY Error!, please refer Quikwallet Payment reference ID ". $id;


                // error payment
                $quikwallet->validateOrder($cart_id, 8, $total, $quikwallet->displayName, $history_message, array(), NULL, false, $customer->secure_key);

                $status_code = "Failed";

                Logger::addLog($history_message, 1);

                $this->context->smarty->assign(array(
                    'status' => $status_code,
                    'responseMsg' => $history_message,
                    'this_path' => $this->module->getPathUri(),
                    'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->module->name.'/'
                ));

                $cart->delete();
                $this->setTemplate('payment_response.tpl');



            }

        }
    }
}
