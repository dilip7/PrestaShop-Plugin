<?php

class Quikwallet extends PaymentModule
{
    private $_html = '';
    private $KEY_ID = null;
    private $KEY_SECRET = null;
    private $KEY_URL = null;


    private $_postErrors = array();

    public function __construct()
    {
        $this->name = 'quikwallet';
        $this->displayName = 'Quikwallet';
        $this->tab = 'payments_gateways';
        $this->version = '0.0.1';
        $this->author = 'Team Quikwallet';

        $config = Configuration::getMultiple(array(
          'QUIKWALLET_PARTNER_ID',
          'QUIKWALLET_SECRET',
          'QUIKWALLET_URL',
          'QUIKWALLET_THEME_COLOR'
        ));

        if (array_key_exists('QUIKWALLET_PARTNER_ID', $config))
        {
            $this->KEY_ID = $config['QUIKWALLET_PARTNER_ID'];
        }

        if (array_key_exists('QUIKWALLET_SECRET', $config))
        {
            $this->KEY_SECRET = $config['QUIKWALLET_SECRET'];
        }

        if (array_key_exists('QUIKWALLET_URL', $config))
        {
            $this->KEY_URL = $config['QUIKWALLET_URL'];
        }

        if(array_key_exists('QUIKWALLET_THEME_COLOR', $config))
        {
            $this->THEME_COLOR = $config['QUIKWALLET_THEME_COLOR'];
        }

        parent::__construct();

        /* The parent construct is required for translations */
        $this->page = basename(__FILE__, '.php');
        $this->description = $this->l('Accept payments with Quikwallet');

        // Both are set to NULL by default
        if ($this->KEY_ID === null OR $this->KEY_SECRET === null OR $this->KEY_URL === null )
            $this->warning = $this->l('Your Quikwallet credentials must be configured in order to use this module correctly');
    }


    public function install()
    {
        //Call PaymentModule default install function
        parent::install();

        $table = _DB_PREFIX_ . 'quik_pay';


      $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `order_no` int(11) NOT NULL AUTO_INCREMENT,
        `date_c` datetime NOT NULL,
        `name` varchar(200) NOT NULL,
        `email_id` varchar(200) NOT NULL,
        `address` varchar(200) NOT NULL,
        `city` varchar(200) NOT NULL,
        `pincode` varchar(10) NOT NULL,
        `mobile` varchar(10) NOT NULL,
        `amount` int(11) NOT NULL,
        `q_id` varchar(100) NOT NULL,
        `hash` varchar(100) NOT NULL,
        `checksum` varchar(200) NOT NULL,
        `order_status` varchar(100) NOT NULL,
        PRIMARY KEY (`order_no`)
      ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8  COLLATE utf8_general_ci ;";


      Db::getInstance()->execute($sql);

        //Create Payment Hooks
        $this->registerHook('payment');
        $this->registerHook('paymentReturn');

    }


    public function uninstall()
    {
        Configuration::deleteByName('QUIKWALLET_PARTNER_ID');
        Configuration::deleteByName('QUIKWALLET_SECRET');
        Configuration::deleteByName('QUIKWALLET_URL');
        parent::uninstall();
    }


    public function getContent()
    {
        $this->_html = '<h2>'.$this->displayName.'</h2>';

        if (Tools::isSubmit('btnSubmit'))
        {
            $this->_postValidation();

            if (empty($this->_postErrors))
            {
                $this->_postProcess();
            }
            else
            {
                foreach ($this->_postErrors AS $err)
                {
                    $this->_html .= "<div class='alert error'>ERROR: {$err}</div>";
                }
            }
        }
        else
        {
            $this->_html .= "<br />";
        }

        $this->_displayquikwallet();
        $this->_displayForm();

        return $this->_html;
    }

    public function execPayment($cart)
    {

        Logger::addLog("QW execPayment reached inside for Order# ".$cart->id);

        $invoice = new Address((int) $cart->id_address_invoice);
        $customer = new Customer((int) $cart->id_customer);
        $address = new Address(intval($cart->id_address_delivery));


        global $smarty;




        // our code

        $productinfo = "Order ". $cart->id;
        $service_provider = 'quikwallet';

        $quikwallet_args = array(
          'amount' => $cart->getOrderTotal(true, 3),
          'firstname' => $address->firstname ,
          'quik_email' =>  $customer->email,
          'phone' => $invoice->phone,
          'productinfo' => $productinfo ,
          'lastname' => $address->lastname,
          'address1' => $address->address1,
          'address2' => $address->address2,
          'city' => $address->city,
          'state' => $address->city,
          'country' => $address->country,
          'zipcode' => $address->postcode,
          'order_id' => $cart->id,
          'service_provider' => $service_provider
        );


        $quikwallet_args_array = array();
        foreach ($quikwallet_args as $key => $value) {
          if (in_array($key, array(
            'quik_email',
            'phone'
          ))) {
            $quikwallet_args_array[] = "<input name='$key' value='$value'/>";
          } else {
            $quikwallet_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
          }
        }    

        /* this theme is not needed as we dont do any thing related to this
        if($this->THEME_COLOR)
        {
            $quikwallet_args_array['theme']['color'] = $this->THEME_COLOR;
        }
        */
        

        $returnUrl = __PS_BASE_URI__."?fc=module&module=quikwallet&controller=validation";

        $inputs_array = implode('' , $quikwallet_args_array);

        $smarty->assign(array(
            'return_url'    => $returnUrl,
            'inputs_array' => $inputs_array,
            'cart_id' => $cart->id
        ));

        return $this->display(__FILE__, 'payment_execution.tpl');
    }


    public function hookPayment($params)
    {
        global $smarty;
        $smarty->assign(array(
        'this_path'         => $this->_path,
        'this_path_ssl'     => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/"));

        return $this->display(__FILE__, 'payment.tpl');
    }


    public function hookPaymentReturn($params)
    {
        global $smarty;
        $state = $params['objOrder']->getCurrentState();
        if ($state == _PS_OS_OUTOFSTOCK_ or $state == _PS_OS_PAYMENT_)
            $smarty->assign(array(
                'total_to_pay'  => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false, false),
                'status'        => 'ok',
                'id_order'      => $params['objOrder']->id
            ));
        else
            $smarty->assign('status', 'failed');

        return $this->display(__FILE__, 'payment_return.tpl');
    }


    private function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit'))
        {
            $keyId = Tools::getValue('KEY_ID');
            $keySecret = Tools::getValue('KEY_SECRET');
            $keyUrl = Tools::getValue('KEY_URL');


            if (empty($keyId))
            {
                $this->_postErrors[] = $this->l('Your Key Id is required.');
            }
            if (empty($keySecret))
            {
                $this->_postErrors[] = $this->l('Your Key Secret is required.');
            }
            if (empty($keyUrl))
            {
                $this->_postErrors[] = $this->l('Your Key Url is required.');
            }
        }
    }

    private function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit'))
        {
            Configuration::updateValue('QUIKWALLET_PARTNER_ID', Tools::getValue('KEY_ID'));
            Configuration::updateValue('QUIKWALLET_SECRET', Tools::getValue('KEY_SECRET'));
            Configuration::updateValue('QUIKWALLET_URL', Tools::getValue('KEY_URL'));
            Configuration::updateValue('QUIKWALLET_THEME_COLOR', Tools::getValue('THEME_COLOR'));

            $this->KEY_ID= Tools::getValue('KEY_ID');
            $this->KEY_SECRET= Tools::getValue('KEY_SECRET');
            $this->KEY_URL= Tools::getValue('KEY_URL');
            $this->THEME_COLOR = Tools::getValue('THEME_COLOR');
        }

        $ok = $this->l('Ok');
        $updated = $this->l('Settings Updated');
        $this->_html .= "<div class='conf confirm'><img src='../img/admin/ok.gif' alt='{$ok}' />{$updated}</div>";
    }




    private function _displayquikwallet()
    {
        $modDesc    = $this->l('This module allows you to accept payments using Quikwallet.');
        $modStatus  = $this->l('Quikwallet online payment service is the right solution for you if you are accepting payments in INR');
        $modconfirm = $this->l('');
        $this->_html .= "<img src='../modules/quikwallet/logo.png' style='float:left; margin-right:15px;' />
            <b>{$modDesc}</b>
            <br />
            <br />
            {$modStatus}
            <br />
            {$modconfirm}
            <br />
            <br />
            <br />";
    }




    private function _displayForm()
    {
        $modquikwallet                = $this->l('Quikwallet Setup');
        $modquikwalletDesc        = $this->l('Please specify the Quikwallet Key Id and Key Secret and Key Url.');

        $modClientLabelKeyId      = $this->l('Quikwallet Key Id');
        $modClientLabelKeySecret       = $this->l('Quikwallet Key Secret');
        $modClientLabelKeyUrl       = $this->l('Quikwallet Key Url');
        $modClientLabelThemeColor       = $this->l('Theme Color');

        $modClientValueKeyId      = $this->KEY_ID;
        $modClientValueKeySecret       = $this->KEY_SECRET;
        $modClientValueKeyUrl       = $this->KEY_URL;
        $modClientValueThemeColor       = $this->THEME_COLOR;

        $modUpdateSettings      = $this->l('Update settings');
        $this->_html .=
        "
        <br />
        <br />
        <p><form action='{$_SERVER['REQUEST_URI']}' method='post'>
                <fieldset>
                <legend><img src='../img/admin/access.png' />{$modquikwallet}</legend>
                        <table border='0' width='500' cellpadding='0' cellspacing='0' id='form'>
                                <tr>
                                        <td colspan='2'>
                                                {$modquikwalletDesc}<br /><br />
                                        </td>
                                </tr>
                                <tr>
                                        <td width='130'>{$modClientLabelKeyId}</td>
                                        <td>
                                                <input type='text' name='KEY_ID' value='{$modClientValueKeyId}' style='width: 300px;' />
                                        </td>
                                </tr>
                                <tr>
                                        <td width='130'>{$modClientLabelKeySecret}</td>
                                        <td>
                                                <input type='text' name='KEY_SECRET' value='{$modClientValueKeySecret}' style='width: 300px;' />
                                        </td>
                                </tr>
                                <tr>
                                        <td width='130'>{$modClientLabelKeyUrl}</td>
                                        <td>
                                                <input type='text' name='KEY_URL' value='{$modClientValueKeyUrl}' style='width: 300px;' />
                                        </td>
                                </tr>
                                <tr>
                                        <td width='130'>{$modClientLabelThemeColor}</td>
                                        <td>
                                                <input type='color' name='THEME_COLOR' value='{$modClientValueThemeColor}' style='width: 300px;' />
                                        </td>
                                </tr>
                                <tr>
                                        <td colspan='2' align='center'>
                                                <input class='button' name='btnSubmit' value='{$modUpdateSettings}' type='submit' />
                                        </td>
                                </tr>
                        </table>
                </fieldset>
        </form>
        </p>
        <br />";
    }
}

?>
