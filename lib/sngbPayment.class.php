<?php

/**
 *
 * @author wa-plugins.ru
 * @name СургутНефтеГазБанк
 * @description СургутНефтеГазБанк
 *
 */
class sngbPayment extends waPayment implements waIPayment {

    private $url = 'https://ecm.sngb.ru:443/Gateway';
    private $test_url = 'https://ecm.sngb.ru:443/ECommerce';
    private $order_id;
    private $currency = array(
        '810' => 'RUB',
    );

    public function allowedCurrency() {
        return $this->currency;
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false) {

        if (!in_array($order_data['currency_id'], $this->allowedCurrency())) {
            throw new waException('Ошибка оплаты. Валюта не поддерживается');
        }

        $trackid = $order_data['id'] . '_' . time();
        $amount = $order_data['amount'];
        $action = '1';

        $salt = $this->merchant . $amount . $trackid . $action . $this->psk;  // Данные хэша       
        $hash = sha1($salt);


        if ($this->sandbox) {
            $url = $this->test_url . '/PaymentInitServlet';
        } else {
            $url = $this->url . '/PaymentInitServlet';
        }

        $params = array(
            'merchant' => $this->merchant,
            'terminal' => $this->terminal,
            'action' => $action,
            'amt' => $amount,
            'trackid' => $trackid,
            'udf1' => str_replace('#', '', $order_data['id_str']),
            'udf5' => $hash
        );

        $response = $this->sendData($url, $params);

        if (preg_match('/PaymentID=([0-9]+)/', $response, $match)) {
            $payment_id = $match[1];
        } else {
            throw new waException('Ошибка оплаты: ' . $response);
        }

        $transaction_data = array(
            'native_id' => $payment_id,
            'amount' => $amount,
            'order_id' => $order_data['id'],
            'customer_id' => $order_data['contact_id'],
            'view_data' => $response,
            'currency_id' => $order_data['currency'],
            'state' => self::STATE_AUTH
        );

        $transaction_data = $this->saveTransaction($transaction_data);

        $view = wa()->getView();
        $view->assign('form_url', trim($response));
        $view->assign('auto_submit', $auto_submit);
        return $view->fetch($this->path . '/templates/payment.html');
    }

    protected function callbackInit($request) {

        if ($request['paymentid'] && empty($request['order_id'])) {
            $params = $request;
            $transaction_model = new waTransactionModel();
            $transaction_data = $transaction_model->getByField('native_id', $request['paymentid']);
            if ($transaction_data) {
                $params = array_merge($params, array(
                    'app_id' => $transaction_data['app_id'],
                    'merchant_id' => $transaction_data['merchant_id'],
                    'order_id' => $transaction_data['order_id'],
                        )
                );
            }
            foreach ($params as $key => $param) {
                $params[$key] = "$key=$param";
            }
            $reply = "REDIRECT=" . $this->getRelayUrl() . "?" . implode('&', $params);
            echo $reply;
        }

        if (!empty($request['order_id'])) {
            $this->app_id = $request['app_id'];
            $this->merchant_id = $request['merchant_id'];
            $this->order_id = $request['order_id'];
        }

        return parent::callbackInit($request);
    }

    protected function callbackHandler($request) {

        if (!$this->order_id) {
            throw new waPaymentException('Ошибка оплаты.');
        }

        $transaction_data = $this->formalizeData($request);



        if (empty($request['Error'])) {
            switch ($request['result']) {
                case "CANCELED":
                    $message = "Операция оплаты отменена.";
                    $app_payment_method = self::CALLBACK_DECLINE;
                    $transaction_data['state'] = self::STATE_CANCELED;
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    break;
                case "NOT APPROVED":
                    switch ($request['responsecode']) {
                        case "04":
                            $message = "Ошибка. Недействительный номер карты.";
                            $app_payment_method = self::CALLBACK_DECLINE;
                            $transaction_data['state'] = self::STATE_DECLINED;
                            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                            break;
                        case "14":
                            $message = " Ошибка. Неверный номер карты.";
                            $app_payment_method = self::CALLBACK_DECLINE;
                            $transaction_data['state'] = self::STATE_DECLINED;
                            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                            break;
                        case "33":
                        case "54":
                            $message = " Ошибка. Истек срок действия карты.";
                            $app_payment_method = self::CALLBACK_DECLINE;
                            $transaction_data['state'] = self::STATE_DECLINED;
                            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                            break;
                        case "Q1":
                            $message = " Ошибка. Неверный срок действия карты или карта просрочена.";
                            $app_payment_method = self::CALLBACK_DECLINE;
                            $transaction_data['state'] = self::STATE_DECLINED;
                            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                            break;
                        case "51":
                            $message = " Ошибка. Недостаточно средств.";
                            $app_payment_method = self::CALLBACK_DECLINE;
                            $transaction_data['state'] = self::STATE_DECLINED;
                            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                            break;
                        case "56":
                            $message = " Ошибка. Неверный номер карты.";
                            $app_payment_method = self::CALLBACK_DECLINE;
                            $transaction_data['state'] = self::STATE_DECLINED;
                            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                            break;
                        default:
                            $message = " Ошибка. Обратитесь в банк, выпустивший карту.";
                            $app_payment_method = self::CALLBACK_DECLINE;
                            $transaction_data['state'] = self::STATE_DECLINED;
                            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    }
                    break;
                case "CAPTURED":
                    if ($request['responsecode'] == "00") {
                        $message = 'Операция проведена успешно.';
                        $app_payment_method = self::CALLBACK_PAYMENT;
                        $transaction_data['state'] = self::STATE_CAPTURED;
                        $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
                    } else {
                        $message = "Ошибка оплаты.";
                        $app_payment_method = self::CALLBACK_DECLINE;
                        $transaction_data['state'] = self::STATE_DECLINED;
                        $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    }
                    break;
                default :
                    $message = "Ошибка оплаты.";
                    $app_payment_method = self::CALLBACK_DECLINE;
                    $transaction_data['state'] = self::STATE_DECLINED;
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
            }
        } else {
            switch ($request['Error']) {
                case 'CGW000029':
                    $message = 'Неверный номер карты';
                    break;
                case 'CGW000289':
                    $message = 'Неверный срок действия карты';
                    break;
                default:
                    $message = "Ошибка оплаты.";
            }
            $app_payment_method = self::CALLBACK_DECLINE;
            $transaction_data['state'] = self::STATE_DECLINED;
            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
        }


        $transaction_data = $this->saveTransaction($transaction_data, $request);
        $result = $this->execAppCallback($app_payment_method, $transaction_data);
        self::addTransactionData($transaction_data['id'], $result);

        return array(
            'template' => $this->path . '/templates/callback.html',
            'back_url' => $url,
            'message' => $message,
        );
    }

    protected function formalizeData($transaction_raw_data) {
        $transaction_data = parent::formalizeData($transaction_raw_data);
        $transaction_data['native_id'] = $transaction_raw_data['paymentid'];
        $transaction_data['order_id'] = $transaction_raw_data['order_id'];
        $transaction_data['view_data'] = json_encode($transaction_raw_data);

        return $transaction_data;
    }

    private function sendData($url, $data) {

        if (!extension_loaded('curl') || !function_exists('curl_init')) {
            throw new waException('PHP расширение cURL не доступно');
        }

        if (!($ch = curl_init())) {
            throw new waException('curl init error');
        }

        if (curl_errno($ch) != 0) {
            throw new waException('Ошибка инициализации curl: ' . curl_errno($ch));
        }

        $postdata = array();

        foreach ($data as $name => $value) {
            $postdata[] = "$name=$value";
        }

        $post = implode('&', $postdata);

        @curl_setopt($ch, CURLOPT_URL, $url);
        @curl_setopt($ch, CURLOPT_POST, 1);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        @curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
        @curl_setopt($ch, CURLE_OPERATION_TIMEOUTED, 120);

        $response = @curl_exec($ch);
        $app_error = null;
        if (curl_errno($ch) != 0) {
            $app_error = 'Ошибка curl: ' . curl_errno($ch);
        }
        curl_close($ch);
        if ($app_error) {
            throw new waException($app_error);
        }
        if (empty($response)) {
            throw new waException('Пустой ответ от сервера');
        }

        return $response;
    }

}
