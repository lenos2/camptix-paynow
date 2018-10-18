<?php
/**
 * CampTix PayNow Payment Method
 *
 * This class handles all PayNow integration for CampTix
 *
 * @since        1.0
 * @package        CampTix
 * @category    Class
 * @author        Tererai Mugova
 */
if (!defined('ABSPATH')) exit; // Exit if accessed directly
class CampTix_Payment_Method_Paynow extends CampTix_Payment_Method
{
    public $id = 'camptix_paynow';
    public $name = 'Paynow';
    public $description = 'CampTix payment methods for Zimbabwe payment gateway Paynow.';
    public $supported_currencies = array('USD');
    /**
     * We can have an array to store our options.
     * Use $this->get_payment_options() to retrieve them.
     */
    protected $options = array();
    function camptix_init()
    {
        $this->options = array_merge(array(
            'merchant_id' => '',
            'merchant_key' => '',
            'sandbox' => false
        ), $this->get_payment_options());
        // IPN Listener
        add_action('template_redirect', array($this, 'template_redirect'));
    }
    function payment_settings_fields()
    {
        $this->add_settings_field_helper('merchant_id', 'Merchant ID', array($this, 'field_text'));
        $this->add_settings_field_helper('merchant_key', 'Merchant Key', array($this, 'field_text'));
    }
    function validate_options($input)
    {
        $output = $this->options;
        if (isset($input['merchant_id']))
            $output['merchant_id'] = $input['merchant_id'];
        if (isset($input['merchant_key']))
            $output['merchant_key'] = $input['merchant_key'];
        return $output;
    }
    function template_redirect()
    {
        if (!isset($_REQUEST['tix_payment_method']) || 'camptix_paynow' != $_REQUEST['tix_payment_method'])
            return;
        if (isset($_GET['tix_action'])) {
            if ('payment_cancel' == $_GET['tix_action'])
                $this->payment_cancel();
            if ('payment_return' == $_GET['tix_action'])
                $this->payment_return();
            if ('payment_notify' == $_GET['tix_action'])
                $this->payment_notify();
        }
    }
    function payment_return()
    {
        global $camptix;
        $this->log(sprintf('Running payment_return.'), null, "payment_return");
        $payment_token = (isset($_REQUEST['tix_payment_token'])) ? trim($_REQUEST['tix_payment_token']) : '';
        if (empty($payment_token))
            return;
        $attendees = get_posts(
            array(
                'posts_per_page' => 1,
                'post_type' => 'tix_attendee',
                'post_status' => array('draft', 'pending', 'publish', 'cancel', 'refund', 'failed'),
                'meta_query' => array(
                    array(
                        'key' => 'tix_payment_token',
                        'compare' => '=',
                        'value' => $payment_token,
                        'type' => 'CHAR',
                    ),
                ),
            )
        );
        if (empty($attendees))
            return;
        $attendee = reset($attendees);
        if ('draft' == $attendee->post_status) {
            return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING);
        } else {
            $access_token = get_post_meta($attendee->ID, 'tix_access_token', true);
            $url = add_query_arg(array(
                'tix_action' => 'access_tickets',
                'tix_access_token' => $access_token,
            ), $camptix->get_tickets_url());
            wp_safe_redirect(esc_url_raw($url . '#tix'));
            die();
        }
    }
    /**
     * Runs when Paynow sends an ITN signal.
     * Verify the payload and use $this->payment_result
     * to signal a transaction result back to CampTix.
     */
    function payment_notify()
    {
        global $camptix;
        $this->log(sprintf('Running payment_notify. Request data attached.'), null, "payment_notify");
        $payment_token = (isset($_REQUEST['tix_payment_token'])) ? trim($_REQUEST['tix_payment_token']) : '';
        $payload = stripslashes_deep($_POST);
        $data_string = '';
        $data_array = array();
        // Dump the submitted variables and calculate security signature
        foreach ($payload as $key => $val) {
            if ($key != 'hash' || $key != "paynowreference") {
                $data_string .= $key . '=' . urlencode($val) . '&';
                $data_array[$key] = $val;
            }
        }
        $hash = $this->createHash($data_array);
        $pfError = false;
        if (0 != strcmp($hash, $payload['hash'])) {
            $pfError = true;
            $this->log(sprintf('ITN request failed, signature mismatch: %s', $payload['hash']));
        }
        // Verify IPN came from Paynow
        if (!$pfError) {
            switch ($payload['status']) {
                case "Paid" :
                    $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED);
                    break;
                case "Delivered" :
                    $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED);
                    break;
                case "Awaiting Delivery" :
                    $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING);
                    break;
            }
        } else {
            $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING);
        }
    }
    public function payment_checkout($payment_token)
    {
        if (!$payment_token || empty($payment_token))
            return false;
        if (!in_array($this->camptix_options['currency'], $this->supported_currencies))
            die(__('The selected currency is not supported by this payment method.', 'camptix'));
        $return_url = add_query_arg(array(
            'tix_action' => 'payment_return',
            'tix_payment_token' => $payment_token,
            'tix_payment_method' => 'camptix_paynow',
        ), $this->get_tickets_url());
        $cancel_url = add_query_arg(array(
            'tix_action' => 'payment_cancel',
            'tix_payment_token' => $payment_token,
            'tix_payment_method' => 'camptix_paynow',
        ), $this->get_tickets_url());
        $notify_url = add_query_arg(array(
            'tix_action' => 'payment_notify',
            'tix_payment_token' => $payment_token,
            'tix_payment_method' => 'camptix_paynow',
        ), $this->get_tickets_url());
        $order = $this->get_order($payment_token);
        
        
        $payload = array(
            // Merchant details
            'id' => $this->options['merchant_id'],
            'reference' => $payment_token,
            'amount' => $order['total'],
            'returnurl' => $return_url,
            'resulturl' => $notify_url,
            "status" => "Message",
        );

        if(!empty($_POST["tix_attendee_info"][1]['email']))
        {
            $payload['authemail'] = $_POST["tix_attendee_info"][1]['email'];
        }
        //generate hash
        $string = "";
        foreach ($payload as $key => $value) {
            $string .= $value;
        }
        $integrationkey = $this->options['merchant_key'];
        $string .= $integrationkey;
        $hash = hash("sha512", $string);
        $payload['hash'] = strtoupper($hash);
        $url = "https://www.paynow.co.zw/Interface/InitiateTransaction";
        $remote_response = wp_remote_post($url, array(
        	'method' => 'POST',
        	'headers' => array(),
        	'body' => $payload
        ));
        
        if ( is_wp_error( $remote_response ) ) {
           $error_message = $remote_response->get_error_message();

            $this->log(sprintf("Remote Request failed:" . $error_message . ': %s', null, "failed_remote_request"));
            return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED);

        } else {
           $parts = explode("&", $remote_response['body']);
            $result = array();
            foreach ($parts as $i => $value) {
                $bits = explode("=", $value, 2);
                $result[$bits[0]] = urldecode($bits[1]);
            }
            
            if ($result['status'] == 'Ok') {
                header('Location:' . $result['browserurl']);
            } else {
                return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED);
            }
        }
        
        return;
    }
    /**
     * Runs when the user cancels their payment during checkout at PayPal.
     * his will simply tell CampTix to put the created attendee drafts into to Cancelled state.
     */
    function payment_cancel()
    {
        global $camptix;
        $this->log(sprintf('Running payment_cancel.'), null, "payment_cancel");
        $payment_token = (isset($_REQUEST['tix_payment_token'])) ? trim($_REQUEST['tix_payment_token']) : '';
        if (!$payment_token)
            die('empty token');
        // Set the associated attendees to cancelled.
        return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED);
    }
    function createHash($values)
    {
        $string = "";
        foreach ($values as $key => $value) {
            if (strtoupper($key) != "HASH") {
                $string .= $value;
            }
        }
        $string .= $this->options['merchant_key'];
        $hash = hash("sha512", $string);
        return strtoupper($hash);
    }
    function urlIfy($fields)
    {
        //url-ify the data for the POST
        $delim = "";
        $fields_string = "";
        foreach ($fields as $key => $value) {
            $fields_string .= $delim . $key . '=' . $value;
            $delim = "&";
        }
        return $fields_string;
    }
    function createMsg($values)
    {
        $fields = array();
        foreach ($values as $key => $value) {
            $fields[$key] = urlencode($value);
        }
        $fields["hash"] = urlencode($this->CreateHash($values));
        $fields_string = $this->urlIfy($fields);
        return $fields_string;
    }
    function parseMsg($msg)
    {
        //convert to array data
        $parts = explode("&", $msg);
        $result = array();
        foreach ($parts as $i => $value) {
            $bits = explode("=", $value, 2);
            $result[$bits[0]] = urldecode($bits[1]);
        }
        return $result;
    }
    function isValidInitResponse($response)
    {
        if ($this->createHash($response) != $response["hash"]) {
            return false;
        } else {
            return true;
        }
    }
    function isValidPollResponse($response)
    {
        if ($this->createHash($response) != $response["hash"]) {
            return false;
        } else {
            return true;
        }
    }
    function pollTransaction($poll_url)
    {
        if (empty($poll_url)) {
            $this->log(sprintf("Poll url should not be empty" . ': %s', null, "paynow_poll_error"));
            return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED);
        }
        
        $remote_response = wp_remote_post($poll_url, array(
        	'method' => 'POST'
        ));
        
        if ( is_wp_error( $remote_response ) ) {
            return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED);
        }
        
        $result = $this->parseMsg($remote_response['body']);
        return $result;
    }
    function displayMessage($message){
        ?>
        <head>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
                /*body {font-family: Arial, Helvetica, sans-serif;}*/

                /* The Modal (background) */
                .modal {
                    display: block; /* Hidden by default */
                    position: fixed; /* Stay in place */
                    z-index: 1; /* Sit on top */
                    padding-top: 300px; /* Location of the box */
                    left: 0;
                    top: 0;
                    width: 100%; /* Full width */
                    height: 100%; /* Full height */
                    overflow: auto; /* Enable scroll if needed */
                    background-color: rgb(0,0,0); /* Fallback color */
                    background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
                }

                /* Modal Content */
                .modal-content {
                    background-color: #fefefe;
                    margin: auto;
                    padding: 20px;
                    border: 1px solid #888;
                    width: 80%;
                }

                /* The Close Button */
                .close {
                    color: #aaaaaa;
                    float: right;
                    font-size: 28px;
                    font-weight: bold;
                }

                .close:hover,
                .close:focus {
                    color: #000;
                    text-decoration: none;
                    cursor: pointer;
                }
            </style>
        </head>
        <body>

<!--        <h2>Error message</h2>-->

        <!-- Trigger/Open The Modal -->
<!--        <button id="myBtn">Open Modal</button>-->

        <!-- The Modal -->
        <div id="myModal" class="modal">

            <!-- Modal content -->
            <div class="modal-content">
                <span class="close">&times;</span>
                <p><?php echo $message ?></p>
            </div>

        </div>

        <script>
            // Get the modal
            var modal = document.getElementById('myModal');

            // Get the button that opens the modal
            //var btn = document.getElementById("myBtn");

            // Get the <span> element that closes the modal
            var span = document.getElementsByClassName("close")[0];

            // When the user clicks the button, open the modal
//            btn.onclick = function() {
//                modal.style.display = "block";
//            }

            // When the user clicks on <span> (x), close the modal
            span.onclick = function() {
                modal.style.display = "none";
            }

            // When the user clicks anywhere outside of the modal, close it
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = "none";
                }
            }
        </script>

        </body>

        <?php
    }
}
?>
