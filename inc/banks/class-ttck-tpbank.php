<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 
 *
 *
 * @author   ttck Team
 * @since    
 *
 */

require_once('class-ttck-base.php');
class WC_Gateway_TTCK_TPbank extends WC_Base_TTCK {
	public function __construct() {
		$this->bank_id 			  = 'tpbank';
		$this->bank_name		  = 	"TPBank";

		// $this->icon               = apply_filters( 'woocommerce_payleo_icon', plugins_url('../assets/tpbank.png', __FILE__ ) );
		$this->has_fields         = false;
		$this->method_title       = sprintf(__('Payment via %s', 'thanh-toan-chuyen-khoan'), $this->bank_name);
		$this->method_description = __('Payment by bank transfer', 'thanh-toan-chuyen-khoan');
		$this->title        = sprintf(__('Payment via %s', 'thanh-toan-chuyen-khoan'), $this->bank_name);
		parent::__construct();
	}
	public function configure_payment()
	{
		$this->method_title       = sprintf(__('Payment via %s', 'thanh-toan-chuyen-khoan'), $this->bank_name);
		$this->method_description = __('Make payment by bank transfer.', 'thanh-toan-chuyen-khoan');
	}
}