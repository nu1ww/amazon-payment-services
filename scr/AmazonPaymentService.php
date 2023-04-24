<?php

namespace Nu1ww\AmazonPaymentService;

class AmazonPaymentService
{
    public $gatewayHost = 'https://sbcheckout.payfort.com/';
    public $language = 'en';
    /**
     * @var string your Merchant Identifier account (mid)
     */
    public $merchantIdentifier = null;

    /**
     * @var string your access code
     */
    public $accessCode = null;

    /**
     * @var string SHA Request passphrase
     */
    public $SHARequestPhrase = null;

    /**
     * @var string SHA Response passphrase
     */
    public $SHAResponsePhrase = null;

    /**
     * @var string SHA Type (Hash Algorith)
     * expected Values ("sha1", "sha256", "sha512")
     */
    public $SHAType = 'sha256';

    /**
     * @var string  command
     * expected Values ("AUTHORIZATION", "PURCHASE")
     */
    public $command = 'PURCHASE';

    /**
     * @var decimal order amount
     */
    public $amount = 100;

    /**
     * @var string order currency
     */
    public $currency = null;

    /**
     * @var string item name
     */
    public $itemName = 'Movie Tpoicket resevation';


    /**
     * @var string  project root folder
     * change it if the project is not on root folder.
     */
    public $projectUrlPath = "";

    public function __construct()
    {

        $this->merchantIdentifier = env('AMAZON_PAYFORT_MERCHANT_IDENTIFIER');
        $this->accessCode = env('AMAZON_PAYFORT_ACCESS_CODE');
        $this->SHARequestPhrase = env('AMAZON_PAYFORT_SHA_REQUEST_PHRASE');
        $this->SHAResponsePhrase = env('AMAZON_PAYFORT_SHA_RESPONSE_PHRASE');
        $this->currency = 'SAR';
    }

    public function processRequest($paymentData)
    {

        $data = $this->getRedirectionData($paymentData);
        $postData = $data['params'];
        $gatewayUrl = $data['url'];

        $form = $this->getPaymentForm($gatewayUrl, $postData);

        return json_encode(array('form' => $form, 'url' => $gatewayUrl, 'params' => $postData));

    }

    public function getRedirectionData($paymentData)
    {

        $gatewayUrl = $this->gatewayHost . 'FortAPI/paymentPage';

        $postData = array(
            'amount' => $this->convertAmazonFortAmount($paymentData['amount'], $this->currency),
            'currency' => strtoupper($this->currency),
            'merchant_identifier' => $this->merchantIdentifier,
            'access_code' => $this->accessCode,
            'merchant_reference' => $paymentData['merchant_reference'],
            'customer_email' => $paymentData['customer_email'],
            'customer_name' => $paymentData['customer_name'],
            'command' => $this->command,
            'language' => $this->language,
            'return_url' => $paymentData['return_url'],
        );


        $postData['signature'] = $this->calculateSignature($postData, 'request');
        $debugMsg = "Fort Redirect Request Parameters \n" . print_r($postData, 1);
        $this->log($debugMsg);
        return array('url' => $gatewayUrl, 'params' => $postData);
    }

    /**
     * @param $gatewayUrl
     * @param $postData
     * @return string
     */
    public function getPaymentForm($gatewayUrl, $postData)
    {
        //$form = "<html xmlns='https://www.w3.org/1999/xhtml'>\n<head></head>\n<body>\n";
        $form = '<form name="myForm" method="post" action="' . $gatewayUrl . '">' . "\n";
        foreach ($postData as $k => $v) {
            $form .= '<input type="hidden" name="' . $k . '" value="' . $v . '">' . "\n";
        }
        //$form .= '<input type="submit" id="submit"></form>' . "\n";
        $form .= "<script>\n";
        //$form .= "\t\talert();\n";
        $form .= "\t\t" . 'myForm.submit();' . "\n";
        $form .= "</script>\n";
        // $form .= "</body>\n</html>";
        return $form;
    }

    /**
     * Check transaction signature
     * @param $fortParams
     * @return array
     */
    public function processResponse($fortParams)
    {
        //$fortParams = array_merge($_GET, $_POST);

        $reason = '';
        $response_code = '';
        $isSuccess = true;
        if (empty($fortParams)) {
            $isSuccess = false;
            $reason = "Invalid Response Parameters";
            $debugMsg = $reason;
        } else {
            //validate payAmazonFort response
            $params = $fortParams;
            $responseSignature = $fortParams['signature'];
            $merchantReference = $params['merchant_reference'];
            unset($params['r']);
            unset($params['signature']);
            unset($params['integration_type']);
            $calculatedSignature = $this->calculateSignature($params, 'response');
            $isSuccess = true;
            $reason = '';

            if ($responseSignature != $calculatedSignature) {
                $isSuccess = false;
                $reason = 'Invalid signature.';
            } else {
                $response_code = $params['response_code'];
                $response_message = $params['response_message'];
                $status = $params['status'];
                if (substr($response_code, 2) != '000') {
                    $isSuccess = false;
                    $reason = $response_message;
                }
            }
        }
        if (!$isSuccess) {
            $p = $params;
            $p['error_msg'] = $reason;

            return [
                "status" => $isSuccess,
                "message" => $reason,
                "ipg_response" => $fortParams,
                "fort_id" => $params['fort_id'],
                "amount" => $this->castAmountFromAmazonFort($params['amount'], $this->currency)
            ];
        } else {

            return [
                "status" => $isSuccess,
                "message" => $reason,
                "ipg_response" => $fortParams,
                "fort_id" => $params['fort_id'],
                "amount" => $this->castAmountFromAmazonFort($params['amount'], $this->currency)
            ];
        }

    }

    /**
     * @param $paymentData
     * @return array
     */
    public function processRefund($paymentData)
    {
        $gatewayUrl = $this->gatewayHost . 'FortAPI/paymentApi';

        $postData = array(
            'amount' => $this->convertAmazonFortAmount($paymentData['amount'], $this->currency),
            'currency' => strtoupper($this->currency),
            'merchant_identifier' => $this->merchantIdentifier,
            'access_code' => $this->accessCode,
            'merchant_reference' => $paymentData['merchant_reference'],
            'command' => "REFUND",
            'language' => $this->language,
            'fort_id' => $paymentData['fort_id'],
            'maintenance_reference' => $paymentData['maintenance_reference'],
        );

        $postData['signature'] = $this->calculateSignature($postData, 'request');
        return $this->callApi($postData, $gatewayUrl);

    }

    /**
     * Generate SDK Token
     * @param $postData
     * @return mixed
     */
    public function genSdkToken($postData)
    {

        $gatewayUrl = $this->gatewayHost . 'FortAPI/paymentApi';
        $postData = [
            "service_command" => "SDK_TOKEN",
            "device_id" => $postData['device_id'],
            "access_code" => $this->accessCode,
            "language" => $this->language,
            "merchant_identifier" => $this->merchantIdentifier,

        ];
        $postData['signature'] = $this->calculateSignature($postData);
        // dd([$postData,$gatewayUrl]);
        return $this->callApi($postData, $gatewayUrl);
    }

    /**
     *
     */
    public function processMerchantPageResponse()
    {
        $fortParams = array_merge($_GET, $_POST);

        $debugMsg = "Fort Merchant Page Response Parameters \n" . print_r($fortParams, 1);
        $this->log($debugMsg);
        $reason = '';
        $response_code = '';
        $success = true;
        if (empty($fortParams)) {
            $success = false;
            $reason = "Invalid Response Parameters";
            $debugMsg = $reason;
            $this->log($debugMsg);
        } else {
            //validate payfort response
            $params = $fortParams;
            $responseSignature = $fortParams['signature'];
            unset($params['r']);
            unset($params['signature']);
            unset($params['integration_type']);
            unset($params['3ds']);
            $merchantReference = $params['merchant_reference'];
            $calculatedSignature = $this->calculateSignature($params, 'response');
            $success = true;
            $reason = '';

            if ($responseSignature != $calculatedSignature) {
                $success = false;
                $reason = 'Invalid signature.';
                $debugMsg = sprintf('Invalid Signature. Calculated Signature: %1s, Response Signature: %2s', $responseSignature, $calculatedSignature);
                $this->log($debugMsg);
            } else {
                $response_code = $params['response_code'];
                $response_message = $params['response_message'];
                $status = $params['status'];
                if (substr($response_code, 2) != '000') {
                    $success = false;
                    $reason = $response_message;
                    $debugMsg = $reason;
                    $this->log($debugMsg);
                } else {
                    $success = true;
                    $host2HostParams = $this->merchantPageNotifyFort($fortParams);
                    $debugMsg = "Fort Merchant Page Host2Hots Response Parameters \n" . print_r($fortParams, 1);
                    $this->log($debugMsg);
                    if (!$host2HostParams) {
                        $success = false;
                        $reason = 'Invalid response parameters.';
                        $debugMsg = $reason;
                        $this->log($debugMsg);
                    } else {
                        $params = $host2HostParams;
                        $responseSignature = $host2HostParams['signature'];
                        $merchantReference = $params['merchant_reference'];
                        unset($params['r']);
                        unset($params['signature']);
                        unset($params['integration_type']);
                        $calculatedSignature = $this->calculateSignature($params, 'response');
                        if ($responseSignature != $calculatedSignature) {
                            $success = false;
                            $reason = 'Invalid signature.';
                            $debugMsg = sprintf('Invalid Signature. Calculated Signature: %1s, Response Signature: %2s', $responseSignature, $calculatedSignature);
                            $this->log($debugMsg);
                        } else {
                            $response_code = $params['response_code'];
                            if ($response_code == '20064' && isset($params['3ds_url'])) {
                                $success = true;
                                $debugMsg = 'Redirect to 3DS URL : ' . $params['3ds_url'];
                                $this->log($debugMsg);
                                echo "<html><body onLoad=\"javascript: window.top.location.href='" . $params['3ds_url'] . "'\"></body></html>";
                                exit;
                                //header('location:'.$params['3ds_url']);
                            } else {
                                if (substr($response_code, 2) != '000') {
                                    $success = false;
                                    $reason = $host2HostParams['response_message'];
                                    $debugMsg = $reason;
                                    $this->log($debugMsg);
                                }
                            }
                        }
                    }
                }
            }

            if (!$success) {
                $p = $params;
                $p['error_msg'] = $reason;
                $return_url = $this->getUrl('error.php?' . http_build_query($p));
            } else {
                $return_url = $this->getUrl('success.php?' . http_build_query($params));
            }
            echo "<html><body onLoad=\"javascript: window.top.location.href='" . $return_url . "'\"></body></html>";
            exit;
        }
    }

    /**
     * @param $fortParams
     * @return mixed
     */
    public function merchantPageNotifyFort($fortParams)
    {

        $gatewayUrl = $this->gatewayHost . 'FortAPI/paymentApi';

        $postData = array(
            'merchant_reference' => $fortParams['merchant_reference'],
            'access_code' => $this->accessCode,
            'command' => $this->command,
            'merchant_identifier' => $this->merchantIdentifier,
            'customer_ip' => $_SERVER['REMOTE_ADDR'],
            'amount' => $this->convertAmazonFortAmount($this->amount, $this->currency),
            'currency' => strtoupper($this->currency),
            'customer_email' => $this->customerEmail,
            'customer_name' => 'John Doe',
            'token_name' => $fortParams['token_name'],
            'language' => $this->language,
            'return_url' => $this->getUrl('route.php?r=processResponse'),
        );

        if (!empty($merchantPageData['paymentMethod']) && $merchantPageData['paymentMethod'] == 'installments_merchantpage') {
            $postData['installments'] = 'YES';
            $postData['plan_code'] = $fortParams['plan_code'];
            $postData['issuer_code'] = $fortParams['issuer_code'];
            $postData['command'] = 'PURCHASE';
        }

        if (isset($fortParams['3ds']) && $fortParams['3ds'] == 'no') {
            $postData['check_3ds'] = 'NO';
        }

        //calculate request signature
        $signature = $this->calculateSignature($postData, 'request');
        $postData['signature'] = $signature;


        $array_result = $this->callApi($postData, $gatewayUrl);


        return $array_result;
    }

    /**
     * Send host to host request to the Fort
     * @param array $postData
     * @param string $gatewayUrl
     * @return mixed
     */
    public function callApi($postData, $gatewayUrl)
    {
        //open connection
        $ch = curl_init();

        //set the url, number of POST vars, POST data
        $useragent = "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:20.0) Gecko/20100101 Firefox/20.0";
        curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json;charset=UTF-8',
            //'Accept: application/json, application/*+json',
            //'Connection:keep-alive'
        ));
        curl_setopt($ch, CURLOPT_URL, $gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_FAILONERROR, 1);
        curl_setopt($ch, CURLOPT_ENCODING, "compress, gzip");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // allow redirects
        //curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // return into a variable
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

        $response = curl_exec($ch);

        //$response_data = array();
        //parse_str($response, $response_data);
        curl_close($ch);

        $array_result = json_decode($response, true);

        if (!$response || empty($array_result)) {
            return false;
        }
        return $array_result;
    }

    /**
     * getMerchantPageData authorized Iframe
     * @param $paymentMethod
     * @return array
     */
    public function getMerchantPageData($paymentMethod)
    {
        $merchantReference = time();
        $returnUrl = $this->getUrl('route.php?r=merchantPageReturn');
        if (isset($_GET['3ds']) && $_GET['3ds'] == 'no') {
            $returnUrl = $this->getUrl('route.php?r=merchantPageReturn&3ds=no');
        }
        $iframeParams = array(
            'merchant_identifier' => $this->merchantIdentifier,
            'access_code' => $this->accessCode,
            'merchant_reference' => $merchantReference,
            'service_command' => 'TOKENIZATION',
            'language' => $this->language,
            'return_url' => $returnUrl,
        );

        $iframeParams['signature'] = $this->calculateSignature($iframeParams, 'request');


        $gatewayUrl = $this->gatewayHost . 'FortAPI/paymentPage';
//echo json_encode($iframeParams);
//exit();
        return array('url' => $gatewayUrl, 'params' => ($iframeParams));
    }

    /**
     * calculate AmazonFort signature by signType
     * @param array $arrData
     * @param string $signType request or response
     * @return string AmazonFort signature
     */
    public function calculateSignature($arrData, $signType = 'request')
    {
        $shaString = '';

        ksort($arrData);
        foreach ($arrData as $k => $v) {
            $shaString .= "$k=$v";
        }

        if ($signType == 'request') {
            $shaString = $this->SHARequestPhrase . $shaString . $this->SHARequestPhrase;
        } else {
            $shaString = $this->SHAResponsePhrase . $shaString . $this->SHAResponsePhrase;
        }
        return hash($this->SHAType, $shaString);

    }

    /**
     * Convert Amount with decimal points
     * @param decimal $amount
     * @param string $currencyCode
     * @return float|int
     */
    public function convertAmazonFortAmount($amount, $currencyCode)
    {
        $new_amount = 0;
        $total = $amount;
        $decimalPoints = $this->getCurrencyDecimalPoints($currencyCode);
        return round($total, $decimalPoints) * (pow(10, $decimalPoints));
    }

    /**
     * Cast to IPG decimal number to actual amount
     * @param $amount
     * @param $currencyCode
     * @return float|int
     */
    public function castAmountFromAmazonFort($amount, $currencyCode)
    {
        $decimalPoints = $this->getCurrencyDecimalPoints($currencyCode);
        return round($amount, $decimalPoints) / (pow(10, $decimalPoints));

    }

    /**
     * @param $currency
     * @return int|mixed
     */
    public function getCurrencyDecimalPoints($currency)
    {
        $decimalPoint = 2;
        $arrCurrencies = array(
            'JOD' => 3,
            'KWD' => 3,
            'OMR' => 3,
            'TND' => 3,
            'BHD' => 3,
            'LYD' => 3,
            'IQD' => 3,
        );
        if (isset($arrCurrencies[$currency])) {
            $decimalPoint = $arrCurrencies[$currency];
        }
        return $decimalPoint;
    }

    /**
     * @param $path
     * @return string
     */
    public function getUrl($path)
    {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $url = $scheme . $_SERVER['HTTP_HOST'] . $this->projectUrlPath . '/' . $path;
        return $url;
    }


    /**
     * Log the error on the disk
     */
    public function log($messages)
    {
        return;
        $messages = "========================================================\n\n" . $messages . "\n\n";
        $file = __DIR__ . '/trace.log';
        if (filesize($file) > 907200) {
            $fp = fopen($file, "r+");
            ftruncate($fp, 0);
            fclose($fp);
        }

        $myfile = fopen($file, "a+");
        fwrite($myfile, $messages);
        fclose($myfile);
    }

}