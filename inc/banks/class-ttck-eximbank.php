<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * ttck Team
 *
 *
 * @author  ttck Team
 * @since    
 *
 */

require_once('class-ttck-base.php');
class WC_Gateway_TTCK_Eximbank extends WC_Base_TTCK
{
	public function __construct()
	{
		$this->bank_id 			  = 'eximbank';
		$this->bank_name		  = "Eximbank";
		
		$this->has_fields         = false;
		$this->method_title       = sprintf(__('Payment via %s', 'thanh-toan-chuyen-khoan'), $this->bank_name);
		$this->method_description = __('Payment by bank transfer', 'thanh-toan-chuyen-khoan');
		$this->title       		  = sprintf(__('Payment via %s', 'thanh-toan-chuyen-khoan'), $this->bank_name);
		parent::__construct();
	}
	public function configure_payment()
	{
		$this->method_title       = sprintf(__('Payment via %s', 'thanh-toan-chuyen-khoan'), $this->bank_name);
		$this->method_description = __('Make payment by bank transfer.', 'thanh-toan-chuyen-khoan');
	}
}
