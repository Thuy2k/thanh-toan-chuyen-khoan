<?php
/*
*
* WC Base Payment Gateway
*
*/

if (!defined('ABSPATH')) exit;

if (!class_exists('WC_Payment_Gateway')) return;


abstract class WC_Base_TTCK extends WC_Payment_Gateway
{
	abstract public function configure_payment();

	/**
	 * Array of locales
	 *
	 * @var array
	 */
	public $locale;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct()
	{

		$this->id                 = 'ttck_up_' . $this->bank_id;
		$this->icon =  apply_filters('woocommerce_icon_' . $this->bank_id, plugins_url('../../assets/' . $this->bank_id . '.png', __FILE__));
		$this->has_fields         = false;
		$this->init_form_fields();
		$this->init_settings();
		// Define user set variables.
		$this->title        = $this->get_option('title');
		$this->description  = $this->get_option('description');
		$this->instructions = $this->get_option('instructions');
		$this->order_content = '';
		global $wp_session;
		// handling cache and order information
		if (true || !isset($wp_session['ttck_banks_setting'])) {
			$this->plugin_settings = TTCKPayment::get_settings();
			$this->oauth_settings = TTCKPayment::oauth_get_settings();
			
		} else {
			
		}
		// BACS account fields shown on the thanks page and in emails.
		$this->account_details = !empty($this->plugin_settings['bank_transfer_accounts'][$this->bank_id])? $this->plugin_settings['bank_transfer_accounts'][$this->bank_id]: array();
			/*array_filter(isset($this->plugin_settings['bank_transfer_accounts'])?$this->plugin_settings['bank_transfer_accounts']:[], function ($account, $k) {
				return $account['bank_name'] == $this->bank_id ;//&& $account['is_show'] == 'yes';
			}, ARRAY_FILTER_USE_BOTH);*/
		#if($this->bank_id=='acb')_print($this->account_details);
		// Actions.
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_account_details' ) );	
		#if(strpos($this->id,'momo')!==false)_print('woocommerce_thankyou_' .$this->id);
		add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'),100);
		#add_action('woocommerce_thankyou', array($this, 'thankyou_page'),100);
		add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);

		//add_action('admin_footer', array($this, 'print_footer'));
		// Customer Emails.

	}
	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __('Enable/Disable', 'woocommerce'),
				'type'    => 'checkbox',
				'label'   => __('Enable bank transfer', 'woocommerce'),
				'default' => 'no',	//no,yes
			),
			'title'           => array(
				'title'       => __('Title', 'woocommerce'),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
				'default'     => sprintf(__('Transfer %s', 'thanh-toan-chuyen-khoan'),$this->bank_name),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __('Description', 'woocommerce'),
				'type'        => 'textarea',
				'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
				'default'     => sprintf(__("Transfer money to our account<b> %s</b>. The order will be confirmed immediately after the transfer", 'thanh-toan-chuyen-khoan'), $this->bank_name),
				//'default'     => __('Make your payment directly into our bank account. Please use your Order ID as the payment reference. Your order will not be shipped until the funds have cleared in our account.', 'woocommerce'),
				'desc_tip'    => true,
			),
			'instructions'    => array(
				'title'       => __('Instructions', 'woocommerce'),
				'type'        => 'textarea',
				'description' => __('Instructions that will be added to the thank you page and emails.', 'woocommerce'),
				'default'     => '',
				'desc_tip'    => true,
			),
			'account_details' => array(
				'type' => 'account_details',
			),
		);
	}

	/**
	 * Generate account details html.
	 *
	 * @return string
	 */
	public function generate_account_details_html()
	{
		#die;
		ob_start();
		$country = WC()->countries->get_base_country();
		$locale  = $this->get_country_locale();
		// Get sortcode label in the $locale array and use appropriate one.
		$sortcode = isset($locale[$country]['sortcode']['label']) ? $locale[$country]['sortcode']['label'] : __('Sort code', 'woocommerce');

?>
<tr valign="top">
    <th scope="row" class="titledesc"><?php esc_html_e('Account details:', 'woocommerce'); ?></th>
    <td class="forminp" id="bacs_accounts">
        <div class="wc_input_table_wrapper">
            <table class="widefat wc_input_table sortable" cellspacing="0">
                <thead>
                    <tr>
                        <th class="sort">&nbsp;</th>
                        <th><?php esc_html_e('Account name', 'woocommerce'); ?></th>
                        <th><?php esc_html_e('Account number', 'woocommerce'); ?></th>
                        <!-- <th><?php #esc_html_e('Bank name', 'woocommerce'); ?></th> -->
                    </tr>
                </thead>
                <tbody class="accounts">
                    <?php
							$i = -1;
							if ($this->account_details) {
								foreach ($this->account_details as $account) {
									$i++;
									echo '<tr class="account">
										<td class="sort"></td>
										<td><input type="text" value="' . esc_attr(wp_unslash($account['account_name'])) . '" name="bacs_account_name[' . esc_attr($i) . ']" /></td>
										<td><input type="text" value="' . esc_attr($account['account_number']) . '" name="bacs_account_number[' . esc_attr($i) . ']" /></td>
										
									</tr>';
									//<td><input type="text" value="' . esc_attr(wp_unslash($account['bank_name'])) . '" name="bacs_bank_name[' . esc_attr($i) . ']" /></td>
								}
							}
							?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="7"><a href="#"
                                class="add button"><?php esc_html_e('+ Add account', 'woocommerce'); ?></a> <a href="#"
                                class="remove_rows button"><?php esc_html_e('Remove selected account(s)', 'woocommerce'); ?></a>
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <script type="text/javascript">
        jQuery(function() {
            jQuery('#bacs_accounts').on('click', 'a.add', function() {

                var size = jQuery('#bacs_accounts').find('tbody .account').length;

                jQuery('<tr class="account">\
									<td class="sort"></td>\
									<td><input type="text" name="bacs_account_name[' + size + ']" /></td>\
									<td><input type="text" name="bacs_account_number[' + size + ']" /></td>\
								</tr>').appendTo('#bacs_accounts table tbody');
                //<td><input type="text" name="bacs_bank_name[' + size + ']" /></td>\

                return false;
            });
        });
        </script>
    </td>
</tr>
<?php
		return ob_get_clean();
	}

	/**
	 * Save account details table.
	 */
	public function save_account_details() {

		$accounts = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verification already handled in WC_Admin_Settings::save()
		if ( isset( $_POST['bacs_account_name'] ) && isset( $_POST['bacs_account_number'] )
			  ) { //&& isset( $_POST['bacs_bank_name'] && isset( $_POST['bacs_sort_code'] ) && isset( $_POST['bacs_iban'] ) && isset( $_POST['bacs_bic'] )

			$account_names   = wc_clean( wp_unslash( $_POST['bacs_account_name'] ) );
			$account_numbers = wc_clean( wp_unslash( $_POST['bacs_account_number'] ) );
			#$bank_names      = wc_clean( wp_unslash( $_POST['bacs_bank_name'] ) );
			#$sort_codes      = wc_clean( wp_unslash( $_POST['bacs_sort_code'] ) );
			#$ibans           = wc_clean( wp_unslash( $_POST['bacs_iban'] ) );
			#$bics            = wc_clean( wp_unslash( $_POST['bacs_bic'] ) );

			foreach ( $account_names as $i => $name ) {
				if ( ! isset( $account_names[ $i ] ) ) {
					continue;
				}
				//$account_numbers[ $i ] 
				$accounts[ ] = array(
					'account_name'   => $account_names[ $i ],
					'account_number' => $account_numbers[ $i ],
					'bank_name'      => $this->bank_id,
					#'sort_code'      => $sort_codes[ $i ],
					#'iban'           => $ibans[ $i ],
					#'bic'            => $bics[ $i ],
				);
			}
		}
		
		// phpcs:enable
		if(!empty($accounts)) {
			$this->plugin_settings['bank_transfer_accounts'][$this->bank_id] = $accounts;
			
			TTCKPayment::update_settings($this->plugin_settings);
			
		}
		#update_option( 'woocommerce_bacs_accounts', $accounts );
	}
	
	/**
	 * Output for the order received page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page($order_id)
	{
		
		
		if ($this->instructions) {
			echo wp_kses_post(wpautop(wptexturize(wp_kses_post($this->instructions))));
		}
		#ttck_console_log($this->account_details);
		global $wp_session;
		if (0&& isset($wp_session['input_thank'])) {
		} else {
			//$wp_session['input_thank'] = true;
			$this->bank_details($order_id, false);
		}
	}

	/**
	 * Add content to the WC emails.
	 *
	 * @param WC_Order $order Order object.
	 * @param bool     $sent_to_admin Sent to admin.
	 * @param bool     $plain_text Email format: plain text or HTML.
	 */
	public function email_instructions($order, $sent_to_admin, $plain_text = false)
	{
		if (!$sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status('on-hold')) {
			if ($this->instructions) {
				echo wp_kses_post(wpautop(wptexturize($this->instructions)) . PHP_EOL);
			}
			global $wp_session;
			if (0&& isset($wp_session['input_thank'])) {
			} else {
				//$wp_session['input_thank'] = true;
				$this->bank_details($order->get_id(), true);
			}
		}
	}

	/**
	 * Get bank details and place into a list format.
	 *
	 * @param int $order_id Order ID.
	 */
	public function bank_details($order_id = 0, $is_sent_email = false) {
			$order_id = absint($order_id);
			if (!$order_id || !function_exists('wc_get_order')) return;

			$order = wc_get_order($order_id);
			if (!$order) return;

			$order_status = $order->get_status();
			$paid_status  = (string) ($this->plugin_settings['order_status']['order_status_after_paid'] ?? '');
			$is_payment   = ("wc-{$order_status}" === $paid_status);

			$auto_check = !empty($this->plugin_settings['auto_check_status']) && (int)$this->plugin_settings['auto_check_status'];

			$bacs_accounts = apply_filters('woocommerce_'.$this->bank_id.'_accounts', $this->account_details, $order_id);
			if (empty($bacs_accounts)) return;

			$output = '';

			// CSS chỉ inject khi chưa paid (giống logic cũ)
			if (!$is_payment) {
				$output .= "<style>
					#image_loading{margin-left:auto;margin-right:auto;width:35%}
					#btnDownloadQR{width:100%;border-radius:0;padding-left:10px!important;padding-right:10px!important;border-color:#0274be;background-color:#0274be;color:#fff;line-height:1}
					td{width:25%}
					#qrcode canvas{border:2px solid #ccc;padding:20px}
					.woocommerce-ttck-qr-scan{text-align:center;margin-top:0}
					.woocommerce-ttck-bank-details{text-align:center;margin-top:10px}
					.woocommerce-ttck-qr-scan img{margin:auto}
					.ttck-timer{font-size:25px;}
					".($auto_check ? 'button[name="submit_paid"]{display:none;}' : '')."
				</style>";
			}

			$output .= '<div id="timer"></div><div id="image_loading"></div><div id="banks_details">';

			// ============ CASE: ĐÃ THANH TOÁN ============
			if ($is_payment) {
				$output .= $this->render_paid_success_block($order);
				echo $output . '</div>';
				return;
			}

			// ============ CASE: CHƯA THANH TOÁN ============
			$payment_gateways = WC()->payment_gateways->payment_gateways();
			$bin_list = array_flip(TTCKPayment::get_list_bin());
			$banks_list = TTCKPayment::get_list_banks();

			$i = 0;
			foreach ($bacs_accounts as $acc) {
				$i++;
				$bacs = (object)$acc;

				$bin = isset($bin_list[$this->bank_id]) ? $bin_list[$this->bank_id] : $this->bank_id;

				$account_fields = apply_filters('woocommerce_ttck_account_fields', [
					'bank_name'      => [
						'label' => __('Bank', 'thanh-toan-chuyen-khoan'),
						'value' => !empty($payment_gateways['ttck_up_'.$bacs->bank_name])
							? $payment_gateways['ttck_up_'.$bacs->bank_name]->bank_name
							: strtoupper($bacs->bank_name),
					],
					'account_number' => [
						'label' => __('Account number', 'thanh-toan-chuyen-khoan'),
						'value' => (string)$bacs->account_number,
					],
					'account_name'   => [
						'label' => __('Account name', 'thanh-toan-chuyen-khoan'),
						'value' => (string)$bacs->account_name,
					],
					'bin'            => [
						'label' => __('Bin', 'thanh-toan-chuyen-khoan'),
						'value' => $bin,
					],
					'amount'         => [
						'label' => __('Amount', 'thanh-toan-chuyen-khoan'),
						'value' => number_format((float)$order->get_total(), 0),
					],
					'content'        => [
						'label' => __('Content', 'thanh-toan-chuyen-khoan'),
						'value' => TTCKPayment::transaction_text(
							($this->plugin_settings['bank_transfer']['transaction_prefix'] ?? '') . $order_id,
							$this->plugin_settings
						),
					],
				], $order_id);

				// Map tên bank thân thiện
				$bn = $account_fields['bank_name']['value'];
				$account_fields['bank_name']['value'] = $banks_list[$bn] ?? $bn;

				// QR
				$qr = $this->get_qrcode_vietqr_img_url($account_fields);
				$qrcode_url  = $qr['img_url'] ?? '';
				$qrcode_page = $qr['pay_url'] ?? '';

				$order_content = !empty($this->order_content)
					? $this->order_content
					: __('Kiểm tra thanh toán', 'thanh-toan-chuyen-khoan');

				$output .= $this->render_unpaid_block($order, $account_fields, $qrcode_url, $order_content, $auto_check, $i, $is_sent_email);
			}

			$output .= '</div>';
			echo $output;
		}

		private function render_paid_success_block(WC_Order $order): string {
			$order_id = $order->get_id();

			// Link My Account / Orders
			$my = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
			$orders_url = function_exists('wc_get_endpoint_url')
				? wc_get_endpoint_url('orders', '', $my)
				: rtrim($my, '/') . '/orders/';

			$html  = '<div class="ttck-success" style="text-align:center">';
			$html .= '<img src="' . esc_url(TTCK_URL.'/assets/success-icon.png') . '" style="max-width:100px;margin:20px" alt="success"/>';
			$html .= '<h2>Bạn đã thanh toán</h2>';
			$html .= '<p>Chúng tôi đã nhận được đơn hàng của bạn và sẽ sớm liên hệ với bạn.</p>';
			$html .= '<p style="margin-top:12px;">
				<a class="button" style="display:inline-block" href="'.esc_url($orders_url).'">Vào tài khoản / Đơn hàng</a>
			</p>';
			$html .= '</div>';

			// ✅ Thông báo admin + tạo audio & auto play
			$text = 'đã nhận được ' . number_format((float)$order->get_total(), 0) . ' ' . $order->get_currency() . ' từ ngân hàng ' . $this->bank_id;
			$html .= $this->simple_speech($text, $order_id);

			return $html;
		}

		private function render_unpaid_block(WC_Order $order, array $account_fields, string $qrcode_url, string $order_content, bool $auto_check, int $idx, bool $is_sent_email): string {
			$order_id = $order->get_id();
			$order_key = $order->get_order_key();
			$order_status = $order->get_status();

			$bank_logo = TTCKPayment::get_bank_icon($account_fields['bank_name']['value']);
			$is_checkout = function_exists('is_page') && is_page('checkout');

			$html = '';

			if ($qrcode_url) {
				$html .= '<section class="woocommerce-ttck-qr-scan">'
					.  '<div id="qrcode" style="text-align:center">'
					.  '<img src="'.esc_url($qrcode_url).'" onerror="qrcode_fallback(this)" alt="ttck QR" width="400" />'
					.  '</div></section>';
			}

			$html .= '<section class="woocommerce-ttck-bank-details">';
			$html .= '<h2 class="wc-ttck-bank-details-heading" style="text-align:center;">VivuPay - Tự động xác nhận</h2>';

			if ($order_status !== 'cancelled') {
				$html .= '<div><p style="color:#856404;max-width:750px;margin:auto;margin-bottom:20px;background:#ffeeba;padding:15px;border-radius:7px;">'
					.  sprintf(__("Please transfer the correct content <b style='font-size: 20px;'>%s</b> for we can confirm the payment", 'thanh-toan-chuyen-khoan'), esc_html($account_fields['content']['value']))
					.  '</p></div>';

				$html .= '<img src="'.esc_url(plugins_url('../../assets/clock.gif', __FILE__)).'" id="image_loading"/>';
				$html .= '<button name="submit_paid" class="submit_paid button" style="margin-bottom:5px;font-size:12px;width:100%;" onclick="fetchStatus(1)" type="button">'
					.  esc_html($order_content)
					.  '</button><span style="color:red;display:block;">Mã QR chỉ có hiệu lực trong 01 phút 30 giây</span>';

				// ✅ nút khách bấm báo shop (AJAX)
				$html .= '
					<button id="ttck_customer_confirm" type="button" class="button" style="width:100%; font-size:12px; margin-top:6px;">
						Tôi đã chuyển khoản (báo shop kiểm tra)
					</button>
					<div id="ttck_customer_confirm_msg" style="margin-top:6px; font-size:12px;"></div>
				';
			}

			// Table details ở checkout
			if ($is_checkout && $order_status !== 'cancelled') {
				$html .= '
				<table class="table table-bordered" style="font-size:12px;max-width:800px;margin-left:auto;margin-right:auto;">
					<tbody>
						<tr><td style="text-align:right;"><strong>Account name:</strong></td><td style="text-align:left;">'.esc_html($account_fields['account_name']['value']).'</td></tr>
						<tr style="background:#FBFBFB;"><td style="text-align:right;"><strong>Account number:</strong></td><td style="text-align:left;">'.esc_html($account_fields['account_number']['value']).'</td></tr>
						<tr><td style="text-align:right;"><strong>Bank:</strong></td><td style="text-align:left;">'.esc_html($account_fields['bank_name']['value']).'</td></tr>
						<tr><td style="text-align:right;"><strong>Amount:</strong></td><td style="text-align:left;">'.esc_html($account_fields['amount']['value']).' <sup>vnđ</sup></td></tr>
						<tr><td style="text-align:right;"><strong>Content*:</strong></td><td style="text-align:left;"><strong style="font-size:20px;">'.esc_html($account_fields['content']['value']).'</strong></td></tr>
					</tbody>
				</table>';
			}

			// JS fetchStatus (giữ y logic cũ) + customer confirm ajax
			$paid_status = (string)($this->plugin_settings['order_status']['order_status_after_paid'] ?? '');
			$html .= '
			<script>
			function fetchStatus(i){
				if("wc-'.esc_js($order_status).'" == "'.esc_js($paid_status).'" || "'.esc_js($order_status).'" == "cancelled"){ return; }

				document.getElementById("image_loading").style.display = "block";
				var btn = document.querySelector(".submit_paid");
				if(btn) btn.style.display="none";
				var noTx = document.getElementById("noTransaction");
				if(noTx) noTx.style.display="none";

				var timeTemp = 0;
				var timer = setInterval(function(){
					jQuery.ajax({
						url : "'.esc_js(site_url('/wp-admin/admin-ajax.php')).'?__tm="+(+new Date),
						type : "post",
						data: {action: "fetch_order_status_ttck", order_id: '.(int)$order_id.'},
						success : function(resp){
							if(resp == "'.esc_js($paid_status).'"){
								// reload
								if (location.href.indexOf("?") === -1) location.href += "?qa=1";
								else location.href += "&qa=1";
							}
							if(resp == "wc-cancelled"){ window.location.reload(); }
						}
					});
					if(timeTemp >= 120000){ clearInterval(timer); return; }
					timeTemp += 3000;
				}, 3000);
			}

			function qrcode_fallback(e){
				jQuery(e).closest("#qrcode").hide();
				jQuery("img.bank-logo").css("display","inline-block");
			}

			jQuery(function($){
				fetchStatus('.(int)$idx.');

				$("#ttck_customer_confirm").on("click", function(){
					if("wc-'.esc_js($order_status).'" == "'.esc_js($paid_status).'" || "'.esc_js($order_status).'" == "cancelled"){ return; }

					var $btn = $(this), $msg = $("#ttck_customer_confirm_msg");
					$msg.text("");
					$btn.prop("disabled", true).text("Đang gửi yêu cầu...");

					$.ajax({
						url: "'.esc_js(site_url('/wp-admin/admin-ajax.php')).'?__tm="+(+new Date),
						type: "post",
						data: {
							action: "ttck_customer_confirm_transfer",
							order_id: '.(int)$order_id.',
							order_key: "'.esc_js($order_key).'"
						},
						success: function(resp){
							if(resp && resp.success){
								$msg.css("color","green").text(resp.data && resp.data.message ? resp.data.message : "Đã gửi yêu cầu.");
								$btn.text("Đã gửi yêu cầu");
							}else{
								$msg.css("color","red").text(resp.data && resp.data.message ? resp.data.message : "Không gửi được, vui lòng thử lại.");
								$btn.prop("disabled", false).text("Tôi đã chuyển khoản (báo shop kiểm tra)");
							}
						},
						error: function(){
							$msg.css("color","red").text("Lỗi kết nối, vui lòng thử lại.");
							$btn.prop("disabled", false).text("Tôi đã chuyển khoản (báo shop kiểm tra)");
						}
					});
				});
			});
			</script>';

			if ($is_sent_email && strpos($html, '.table-bordered') === false) {
				$html .= '<style>.table-bordered{border:1px solid rgba(0,0,0,.1);}</style>';
			}

			$html .= '</section>';

			return $html;
		}
	
		public function simple_speech(string $text, int $pos_order_id): string {
			$site_title = get_bloginfo('name');
			$msg_post = trim($site_title . ' ' . $text . '. Mã đơn hàng: ' . $pos_order_id);

			// ✅ báo admin trước
			if (class_exists('WPC_PendingEmail') && is_callable(['WPC_PendingEmail','wp_kpoint'])) {
				WPC_PendingEmail::wp_kpoint($msg_post, '0931576886', 'send_kpoint_admin', $pos_order_id);
			}

			// ✅ tạo voice sau khi notify
			$rel = $this->convert_to_mp3($msg_post); // trả về relative path: audio-bank/xxx.mp3
			if (!$rel) return '';

			$link_mp3 = content_url($rel);
			$id = 'bizcityAudio_' . $pos_order_id;

			return '
			<div class="bizcity-audio" style="margin-top:10px;text-align:center;">
				<audio id="'.esc_attr($id).'" preload="auto" playsinline>
					<source src="'.esc_url($link_mp3).'" type="audio/mpeg">
				</audio>
				<button type="button" class="button" onclick="(function(){var a=document.getElementById(\''.esc_js($id).'\'); if(a) a.play();})();">
					🔊 Nghe thông báo
				</button>
			</div>
			<script>
			(function(){
				var a = document.getElementById("'.esc_js($id).'");
				if(!a) return;
				// thử autoplay
				var p = a.play();
				if(p && p.catch){ p.catch(function(){ /* browser chặn autoplay => dùng nút */ }); }
			})();
			</script>';
		}

		private function convert_to_mp3(string $text): string {
			$dir = WP_CONTENT_DIR . '/audio-bank';
			if (!is_dir($dir)) {
				wp_mkdir_p($dir);
				@chmod($dir, 0755);
			}

			// filename theo md5 nội dung
			$hash = md5($text);
			$abs  = $dir . '/' . $hash . '.mp3';
			$rel  = 'audio-bank/' . $hash . '.mp3';

			if (file_exists($abs) && filesize($abs) > 0) {
				return $rel;
			}

			// Google TTS (giữ như code cũ)
			$q = rawurlencode($text);
			$url = 'https://translate.google.com.vn/translate_tts?ie=UTF-8&client=tw-ob&q='.$q.'&tl=vi';

			$mp3 = @file_get_contents($url);
			if (!$mp3) return '';

			$ok = @file_put_contents($abs, $mp3);
			if (!$ok) return '';

			@chmod($abs, 0644);
			return $rel;
		}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment($order_id)
	{

		$order = wc_get_order($order_id);

		if ($order->get_total() > 0) {
			// Mark as on-hold (we're awaiting the payment).
			$order->update_status(apply_filters('woocommerce_bacs_process_payment_order_status', 'on-hold', $order), __('Awaiting BACS payment', 'woocommerce'));
		} else {
			$order->payment_complete();
		}
		// Remove cart.
		WC()->cart->empty_cart();

		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url($order),
		);
	}
	/**
	 * Get country locale if localized.
	 *
	 * @return array
	 */
	public function get_country_locale()
	{

		if (empty($this->locale)) {

			// Locale information to be used - only those that are not 'Sort Code'.
			$this->locale = apply_filters(
				'woocommerce_get_bacs_locale',
				array(
					'AU' => array(
						'sortcode' => array(
							'label' => __('BSB', 'woocommerce'),
						),
					),
					'CA' => array(
						'sortcode' => array(
							'label' => __('Bank transit number', 'woocommerce'),
						),
					),
					'IN' => array(
						'sortcode' => array(
							'label' => __('IFSC', 'woocommerce'),
						),
					),
					'IT' => array(
						'sortcode' => array(
							'label' => __('Branch sort', 'woocommerce'),
						),
					),
					'NZ' => array(
						'sortcode' => array(
							'label' => __('Bank code', 'woocommerce'),
						),
					),
					'SE' => array(
						'sortcode' => array(
							'label' => __('Bank code', 'woocommerce'),
						),
					),
					'US' => array(
						'sortcode' => array(
							'label' => __('Routing number', 'woocommerce'),
						),
					),
					'ZA' => array(
						'sortcode' => array(
							'label' => __('Branch code', 'woocommerce'),
						),
					),
				)
			);
		}

		return $this->locale;
	}
	public function get_qrcode_vietqr_img_url($account_fields)
	{
		$bank = $account_fields['bin']['value'];
		$accountNo = $account_fields['account_number']['value'];
		$accountName = $account_fields['account_name']['value'];
		$acqId = $account_fields['bin']['value'];
		$addInfo = $account_fields['content']['value'];
		$amount = (int)preg_replace("/([^0-9\\.])/i", "", $account_fields['amount']['value']);
			
		if(is_numeric($acqId)) {
			$format = "qr_only";
			$img_url = "https://api.vietqr.io/{$acqId}/{$accountNo}/{$amount}/{$addInfo}/{$format}.jpg";
			$pay_url = "https://api.vietqr.io/{$acqId}/{$accountNo}/{$amount}/{$addInfo}";
		}
		else {
			$img_url = "";
			$pay_url = "";
			if($bank=='momo') {
				$img_url = get_rest_url(null, "bck/v1/qrcode?app=momo&phone={$accountNo}&price={$amount}&content=".urlencode($addInfo));
			}
			else if($bank=='viettelpay') {
				$img_url = get_rest_url(null, "ttck/v1/qrcode?app=viettelpay&phone={$accountNo}&price={$amount}&content=".urlencode($addInfo));
			}
		}
		return array(
			"img_url" => $img_url,
			"pay_url" => $pay_url,
		);
	}
	public function get_qrcode_vietqr($account_fields)
	{
		global $wp;
		$url = 'https://api.vietqr.io/v1/generate';
		$body = array(
			"accountNo" => $account_fields['account_number']['value'],
			"accountName" => $account_fields['account_name']['value'] ?: 'CHU CHU CHU',
			"acqId" => $account_fields['bin']['value'],
			"addInfo" => $account_fields['content']['value'],
			"amount" => (int)preg_replace("/([^0-9\\.])/i", "", $account_fields['amount']['value']),
			"format" => "qr_only"
		);
		$args = array(
			'body'        => json_encode($body),
			'headers' => array(
				'x-api-key' => 'we-l0v3-v1et-qr',
				"x-client-id" => get_site_url(),
				"content-type" => "application/json",
				"referer" => home_url(add_query_arg(array(!empty($_GET)? $_GET:array()), $wp->request))
			)
		);
		$response = wp_remote_post($url, $args);
		#$body     = wp_remote_retrieve_body($response);
		if (is_wp_error($response)) {
			return null;
		}
		if ($response['response']['code'] == 200 || $response['response']['code'] == 201) {
			$body     = wp_remote_retrieve_body($response);
			return $body;
		}
		return null;
	}
	public function get_description()
	{
		$des = apply_filters('woocommerce_gateway_description', $this->description, $this->id);
		#if ($this->bank_id != "momo")
		#	$des .= __(" <div class='power_by'>Power by PaidChecker</div>", 'thanh-toan-chuyen-khoan');
		return $des;
	}
	public static function payment_name($name) {
		return str_replace('ttck_up_', '', $name);
	}
}