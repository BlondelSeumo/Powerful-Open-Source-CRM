<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Mollie extends App_Controller
{
    /**
     * Show message to the customer whether the payment is successfully
     *
     * @return mixed
     */
    public function verify_payment()
    {
        $invoiceid = $this->input->get('invoiceid');
        $hash      = $this->input->get('hash');
        check_invoice_restrictions($invoiceid, $hash);

        $this->db->where('id', $invoiceid);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        $oResponse = $this->mollie_gateway->fetch_payment($invoice->token);

        if ($oResponse->isSuccessful()) {
            $data = $oResponse->getData();

            if ($data['status'] == 'paid') {
                set_alert('success', _l('online_payment_recorded_success'));
            } else {
                set_alert('danger', $data['details']['failureMessage'] ?? '');
            }
        } else {
            set_alert('danger', $oResponse->getMessage());
        }

        redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
    }

    /**
     * Handle the mollie webhook
     *
     * @param  string $key
     *
     * @return mixed
     */
    public function webhook($key = null)
    {
        $ip = $this->input->ip_address();

        $trans_id  = $this->input->post('id');
        $oResponse = $this->mollie_gateway->fetch_payment($trans_id);

        if ($oResponse->isSuccessful()) {
            $data = $oResponse->getData();

            // log_message('error', json_encode($data));

            // When key is not passed is checked at the top with the ip range
            if ($data['metadata']['webhookKey'] === $key) {
                if ($data['status'] == 'paid') {
                    $this->db->where('transactionid', $trans_id);
                    $this->db->where('invoiceid', $data['metadata']['order_id']);
                    $payment = $this->db->get(db_prefix() . 'invoicepaymentrecords')->row();

                    if ($data['amount']['value'] == $data['amountRemaining']['value']) {
                        // New payment
                        $this->mollie_gateway->addPayment([
                                'amount'        => $data['amount']['value'],
                                'invoiceid'     => $data['metadata']['order_id'],
                                'paymentmethod' => $data['method'],
                                'transactionid' => $trans_id,
                          ]);
                    } elseif ($data['amount']['value'] == $data['amountRefunded']['value']) {
                        // log_message('error', 'Fully refunded');
                        $this->db->where('id', $payment->id);
                        $this->db->delete(db_prefix() . 'invoicepaymentrecords');
                        update_invoice_status($data['metadata']['order_id']);
                    } elseif ($data['amount']['value'] != $data['amountRemaining']['value']) {
                        // log_message('error', 'Partially refunded');
                        $this->db->where('id', $payment->id);
                        $this->db->update(db_prefix() . 'invoicepaymentrecords', ['amount' => $data['amountRemaining']['value']]);
                        update_invoice_status($data['metadata']['order_id']);
                    }
                }
            }
        }
    }
}
