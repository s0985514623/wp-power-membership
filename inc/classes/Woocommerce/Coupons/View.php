<?php
/**
 * 優惠券 View
 */

declare(strict_types=1);

namespace J7\PowerMembership\WooCommerce\Coupons;

use J7\PowerMembership\Utils\Base;
use J7\PowerMembership\Admin\Menu\Settings;
use J7\PowerMembership\WooCommerce\Coupons\Metabox;
use J7\PowerMembership\Plugin;


/**
 * 優惠券 View
 */
final class View {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 進一步的優惠券
	 *
	 * @var array
	 */
	public $further_coupons = [];

	/**
	 * 特殊優惠券
	 *
	 * @var array
	 */
	public $special_coupons = [ 'full_gift' ];

	/**
	 * 建構子
	 */
	public function __construct() {
		\add_action('setup_theme', [ $this, 'init' ], 110);
		// 增加AJAX處理
		\add_action('init', [ $this, 'register_ajax_hooks' ]);
	}
	/**
	 * AJAX註冊
	 */
	public function register_ajax_hooks(): void {
		\add_action('wp_ajax_get_show_available_coupons', [ $this, 'show_available_coupons' ]);
		\add_action('wp_ajax_nopriv_get_show_available_coupons', [ $this, 'show_available_coupons' ]);
	}
	/**
	 * 初始化
	 */
	public function init(): void {
		global $power_plugins_settings;
		\add_action('woocommerce_before_checkout_form', [ $this, 'show_award_deduct' ], 20, 1);
		if ($power_plugins_settings[ Settings::ENABLE_SHOW_AVAILABLE_COUPONS_FIELD_NAME ] ?? false) {
			\add_action('wp_enqueue_scripts', [ $this, 'enqueue_assets' ]);
			\add_action('woocommerce_before_checkout_form', [ $this, 'show_available_coupons' ], 10, 1);
			\add_filter('woocommerce_coupon_validate_minimum_amount', [ $this, 'modify_minimum_amount_condition' ], 200, 3);
			\add_filter('woocommerce_coupon_is_valid', [ $this, 'custom_condition' ], 200, 3);
		}

		if (!( $power_plugins_settings[ Settings::ENABLE_SHOW_COUPON_FORM_FIELD_NAME ] ?? false )) {
			\add_action('init', [ $this, 'remove_wc_coupon_form' ], 20);
		}

		\add_action('wp_ajax_award_deduct_point', [ $this, 'award_deduct_point' ]);
		\add_action('woocommerce_cart_calculate_fees', [ $this, 'add_custom_fee' ]);
		\add_action('woocommerce_checkout_order_created', [ $this, 'exec_deduct_point' ]);
		\add_action('woocommerce_cart_emptied', [ $this, 'clear_cart_and_session' ]);
		\add_action('init', [ $this, 'clear_fee' ]);
		// 訂單取消時，歸還購物金；更新: 折抵的購物金不退
		// \add_action('woocommerce_order_status_cancelled', [ $this, 'restore_award_deduct_point' ]);
	}

	/**
	 * 移除 WooCommerce 優惠券表單
	 */
	public function remove_wc_coupon_form(): void {
		\remove_action('woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10);
	}

	/**
	 * 添加資產
	 */
	public function enqueue_assets(): void {
		if (\is_checkout()) {
			\wp_enqueue_style('dashicons');
			\wp_enqueue_style('handle-coupon', Plugin::$url . '/inc/assets/css/front.min.css', [], Plugin::$version);

			\wp_enqueue_script( 'jquery-blockui' );
			\wp_enqueue_script(
				'handle-coupon',
				Plugin::$url . '/inc/assets/js/handle-coupon.js',
				[ 'jquery' ,'wc-checkout' ],
				Plugin::$version,
				[
					'strategy'  => 'async',
					'in_footer' => true,
				]
				);
		}
	}
	/**
	 * 顯示購物金折抵
	 *
	 * @param \WC_Checkout $checkout 結帳頁面
	 */
	public function show_award_deduct( $checkout ): void {

		$custom_fee  = \WC()->session->get('custom_fee');
		$current_fee = $custom_fee ? (int) $custom_fee['amount'] : 0;

		$user_point       = \gamipress_get_user_points(\get_current_user_id(), 'ee_point');
		$user_point_price = \wc_price($user_point + $current_fee);
		$sub_total        = (int) WC()->cart->subtotal;

		$coupons = $this->get_valid_award_deduct_coupons(); // 取得購物車折抵優惠
		if (empty($coupons)) {
			return;
		}

		echo '<div class="mb-2 py-2">';
		foreach ($coupons as $coupon) {
			$deduct_ratio      = $coupon->get_amount() / 100;
			$max_deduct_amount = \floor($sub_total * $deduct_ratio);

			printf(
				/*html*/'<p class="mb-0">購物金折抵，最高可以折抵購物車金額 %1$s %% 即 %2$s 元，您目前有 <span id="user-point" >%3$s</span> 元購物金</p>',
				$coupon->get_amount(),
				\wc_price($max_deduct_amount),
				$user_point_price
			);

			$name = 'award_deduct_point';
			printf(
				/*html*/'
					<input type="number" class="input-text inline-block w-40" name="%1$s" id="%1$s" placeholder="" value="">
					<button id="%1$s-apply" data-coupon_id="%2$s" data-user_point="%3$d" type="button" class="button">折抵</button>
				',
				$name,
				$coupon->get_id(),
				$user_point,
			);

		}
		echo '</div>';
	}

	/**
	 * 顯示可用優惠券
	 *
	 * @param \WC_Checkout $checkout 結帳頁面
	 */
	public function show_available_coupons( $checkout ): void {
		$coupons         = $this->get_valid_coupons(); // 取得網站一般優惠
		$special_coupons = $this->get_valid_special_coupons(); // 取得特殊優惠
		// 當沒有可用優惠以及進一步的優惠券時，不顯示
		if (empty($coupons)&&empty($this->further_coupons)&&empty($special_coupons)) {
			return;
		}
		global $power_plugins_settings;
		// var_dump($power_plugins_settings);
		$coupons = $this->sort_coupons($coupons);
		// 開始構建 HTML
		$html  = '<div class="power-coupon">';
		$html .= '<h2 class="">消費滿額折扣</h2>';
		$html .= '<div class="mb-2 py-2">';

		// 添加特殊優惠券output buffering 來捕獲 `show_special_coupons` 的輸出
		ob_start();
		$html .= $this->show_special_coupons($special_coupons, true); // 這裡改成返回 HTML 而不是直接 `echo`
		$html .= ob_get_clean();
		// 迴圈加載優惠券模板
		foreach ($coupons as $coupon) {
			$props = $this->get_coupon_props($coupon);

			// 使用 output buffering 來捕獲 `load_template` 的輸出
			ob_start();
			\load_template(
				__DIR__ . '/templates/basic.php',
				false,
				[
					'coupon' => $coupon,
					'props'  => $props,
				]
			);
			$html .= ob_get_clean();
		}

		$html .= '</div>';
		$html .= '</div>';
		// 最後一次輸出 HTML

		if (wp_doing_ajax()) {
			// 這是 AJAX 請求
			\wp_send_json($html);
		} else {
			// 這是一般請求
			echo $html;
		}
	}
	/**
	 * 顯示可用的生日禮/滿額送禮/專屬單品折扣
	 *
	 * @param \WC_Coupon $special_coupons 優惠券
	 * @return void
	 */
	public function show_special_coupons( $special_coupons ): void {
		foreach ($special_coupons as $coupon) {
			$props = $this->get_coupon_props($coupon);
			\load_template(
						__DIR__ . '/templates/special.php',
						false,
						[
							'coupon' => $coupon,
							'props'  => $props,
						]
						);
		}
	}

	/**
	 * 取得有效的生日禮/滿額送禮/專屬單品優惠券
	 *
	 * @return array
	 */
	public function get_valid_special_coupons(): array {
		$coupon_ids = \get_posts(
			[
				'posts_per_page' => -1,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'OR',
					[
						'key'     => 'discount_type',
						'value'   => $this->special_coupons,
						'compare' => 'IN',
					],
					[
						'key'     => 'birthday_gift',
						'value'   => 'yes', // 替換為您希望匹配的值
						'compare' => '==', // 可用於部分匹配
					],
					[
						'key'     => 'auto_apply',
						'value'   => 'yes', // 替換為您希望匹配的值
						'compare' => '==', // 可用於部分匹配
					],
				],
			]
		) ?? [];
		$coupons    = array_map(
			function ( $coupon_id ) {
				return new \WC_Coupon($coupon_id);
			},
			$coupon_ids
			);

		// 生日禮金篩選
		$coupons   = array_filter(
			$coupons,
			function ( $coupon ) {
				// 取得優惠券類型是否為生日禮
				$birthday_gift = $coupon->get_meta('birthday_gift');
				// 如果是的話，判斷今天是否已經過了生日，以及今天是否在生日後三個月內
				if ($birthday_gift === 'yes') {
					$user_id       = \get_current_user_id();
					$user_birthday = \get_user_meta($user_id, 'birthday', true); // YYYY-MM-DD
					$today         = wp_date('Y-m-d');

					if (!empty($user_birthday)) {
						// 判斷是否已使用過這張優惠券
						$has_user_used_coupon = $this->has_user_used_coupon($user_id, $coupon, $user_birthday);
						$current_year         = wp_date('Y');
						$birthday_this_year   = $current_year . '-' . wp_date('m-d', strtotime($user_birthday));

						// 如果生日已經過了今年，檢查是否在三個月內
						if ($today >= $birthday_this_year) {
								$three_months_later = wp_date('Y-m-d', strtotime($birthday_this_year . ' +3 months'));
							if ($today <= $three_months_later && !$has_user_used_coupon) {
									return true;
							}
						} else {
								// 如果生日尚未到，檢查去年生日的三個月後是否跨到今年
								$birthday_last_year = ( $current_year - 1 ) . '-' . wp_date('m-d', strtotime($user_birthday));
								$three_months_later = wp_date('Y-m-d', strtotime($birthday_last_year . ' +3 months'));
							if ($today <= $three_months_later&&!$has_user_used_coupon) {
									return true;
							}
						}
					}
					return false;
				}
				return true;
			}
		);
		$discounts = new \WC_Discounts(WC()->cart);

		foreach ($coupons as $key => $coupon) {
			$valid = $discounts->is_coupon_valid($coupon);
			if (is_wp_error($valid)) {
				unset($coupons[ $key ]);
				continue;
			}
			// 自動應用折價券
			$coupon_code = $coupon->get_code();
			if (!WC()->cart->has_discount($coupon_code)) {
				WC()->cart->apply_coupon($coupon_code);

			}
		}
		return $coupons;
	}
	/**
	 * 取得有效的優惠券
	 *
	 * @return array
	 */
	public function get_valid_coupons(): array {
		$coupon_ids = \get_posts(
			[
				'posts_per_page' => -1,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'OR',
					[
						'key'     => Metabox::HIDE_THIS_COUPON_FIELD_NAME,
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => Metabox::HIDE_THIS_COUPON_FIELD_NAME,
						'value'   => 'yes',
						'compare' => '!=',
					],
				],
			]
			) ?? [];

		$coupons = array_map(
			function ( $coupon_id ) {
				return new \WC_Coupon($coupon_id);
			},
			$coupon_ids
			);

		$coupons = array_filter(
			$coupons,
			function ( $coupon ) {
				return 'award_deduct' !== $coupon->get_discount_type()&&'yes'!==$coupon->get_meta('birthday_gift')&&'yes'!==$coupon->get_meta('auto_apply');
			}
			);

		$discounts = new \WC_Discounts(WC()->cart);

		foreach ($coupons as $key => $coupon) {
			$valid = $discounts->is_coupon_valid($coupon);
			if (is_wp_error($valid)) {
				unset($coupons[ $key ]);
			}
			// 如果是自動應用的折價券，則一樣unset
			if ('full_gift' === $coupon->get_discount_type()) {
				unset($coupons[ $key ]);
			}
		}

		return $coupons;
	}

	/**
	 * 取得有效的購物金折抵優惠券
	 *
	 * @return array
	 */
	public function get_valid_award_deduct_coupons(): array {
		$coupon_ids = \get_posts(
			[
				'posts_per_page' => -1,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_key'       => 'discount_type',
				'meta_value'     => 'award_deduct',
			]
			) ?? [];

		$coupons = array_map(
			function ( $coupon_id ) {
				return new \WC_Coupon($coupon_id);
			},
			$coupon_ids
			);

		$discounts = new \WC_Discounts(WC()->cart);

		foreach ($coupons as $key => $coupon) {
			$valid = $discounts->is_coupon_valid($coupon);
			if (is_wp_error($valid)) {
				unset($coupons[ $key ]);
			}
		}

		return $coupons;
	}

	/**
	 * 自訂條件
	 *
	 * @param bool          $is_valid 是否有效
	 * @param \WC_Coupon    $coupon 優惠券
	 * @param \WC_Discounts $discounts 折扣
	 * @return bool
	 */
	public function custom_condition( bool $is_valid, \WC_Coupon $coupon, \WC_Discounts $discounts ): bool {
		$condition_by_membership_ids = $this->filter_condition_by_membership_ids($coupon);
		$condition_by_first_purchase = $this->filter_condition_by_first_purchase($coupon);
		$condition_by_min_quantity   = $this->filter_condition_by_min_quantity($coupon);
		// 增加生日禮/滿額送禮/專屬單品折扣條件
		$condition_by_full_gift = $this->filter_condition_by_full_gift($coupon);

		return $condition_by_membership_ids && $condition_by_first_purchase && $condition_by_min_quantity && $condition_by_full_gift && $is_valid;
	}
	// 增加生日禮/滿額送禮/專屬單品折扣條件Callback
	/**
	 * 過濾滿額贈禮條件
	 *
	 * @param \WC_Coupon $coupon 優惠券
	 * @return bool
	 */
	private function filter_condition_by_full_gift( \WC_Coupon $coupon ): bool {
		$discount_type = $coupon->get_discount_type();
		if ($discount_type==='full_gift') {
			// 取得優惠券類型
			$shipping_classes_ids = $coupon->get_meta('allowed_shipping_classes');
			$all_shipping_classes = $this->get_shipping_classes_slug($shipping_classes_ids);
			// 判斷是否為冷凍商品的運送方式
			// TODO freezing為冷凍商品的運送方式，未來改成動態獲得
			if (in_array('freezing', $all_shipping_classes)) {
				// 判斷購物車是否有冷凍商品
				$cart_items   = WC()->cart->get_cart();
				$has_freezing = false;
				foreach ($cart_items as $cart_item) {
					$product_id     = $cart_item['product_id'];
					$product        = wc_get_product($product_id);
					$shipping_class = $product->get_shipping_class();
					if ($shipping_class === 'freezing') {
						$has_freezing =true;
						break;
					}
				}
				return $has_freezing;
			} else {
				$cart_items = WC()->cart->get_cart();
				$has_normal = false;
				foreach ($cart_items as $cart_item) {
					$product_id     = $cart_item['product_id'];
					$product        = wc_get_product($product_id);
					$shipping_class = $product->get_shipping_class();
					if ($shipping_class !== 'freezing') {
						$has_normal =true;
						break;
					}
				}
				return $has_normal;
			}
		}
		return true;
	}
	/**
	 * 過濾會員等級條件
	 *
	 * @param \WC_Coupon $coupon 優惠券
	 * @return bool
	 */
	private function filter_condition_by_membership_ids( \WC_Coupon $coupon ): bool {
		// 或是 allowed_membership_ids 包含 user 的 membership id 的 coupon
		$allowed_membership_ids = $coupon->get_meta(Metabox::ALLOWED_MEMBER_LV_FIELD_NAME);
		$allowed_membership_ids = is_array($allowed_membership_ids) ? $allowed_membership_ids : [];
		$user_id                = \get_current_user_id();
		$user_member_lv_id      = \gamipress_get_user_rank_id($user_id, Base::MEMBER_LV_POST_TYPE);
		if (in_array($user_member_lv_id, $allowed_membership_ids)) {
			return true;
		}

		// Filter 出 allowed_membership_ids 是 [] 的 coupon (沒有限制)
		return empty($allowed_membership_ids);
	}

	/**
	 * 過濾首次購買條件
	 *
	 * @param \WC_Coupon $coupon 優惠券
	 * @return bool
	 */
	private function filter_condition_by_first_purchase( \WC_Coupon $coupon ): bool {
		$value = $coupon->get_meta(Metabox::FIRST_PURCHASE_COUPON_FIELD_NAME);
		if ('yes' === $value) {
			return $this->is_first_purchase();
		}

		return true;
	}

	/**
	 * 過濾最小數量條件
	 *
	 * @param \WC_Coupon $coupon 優惠券
	 * @return bool
	 */
	private function filter_condition_by_min_quantity( \WC_Coupon $coupon ): bool {
		$min_quantity = (int) $coupon->get_meta(Metabox::MIN_QUANTITY_FIELD_NAME);
		if (!empty($min_quantity)) {
			$cart                 = \WC()->cart;
			$cart_item_quantities = (int) array_sum($cart->get_cart_item_quantities());
			return $cart_item_quantities >= $min_quantity;
		}

		return true;
	}

	/**
	 * 隱藏小的coupon
	 * 只出現大的coupon
	 *
	 * @param array $available_coupons 可用優惠券
	 * @return array
	 */
	public function sort_coupons( array $available_coupons ): array {
		global $power_plugins_settings;
		$further_coupons = $this->further_coupons;

		usort(
			$available_coupons,
			function ( $a, $b ) {
				return (int) $this->get_coupon_amount($b) - (int) $this->get_coupon_amount($a);
			}
			);
		usort(
			$further_coupons,
			function ( $a, $b ) {
				return (int) $a->get_minimum_amount() - (int) $a->get_minimum_amount();
			}
			);
		// 重新篩選根據運送狀態篩選further_coupons
		$further_coupons = array_filter(
			$further_coupons,
			function ( $further_coupon ) {
				$condition_by_membership_ids = $this->filter_condition_by_membership_ids($further_coupon);
				$condition_by_first_purchase = $this->filter_condition_by_first_purchase($further_coupon);
				$condition_by_min_quantity   = $this->filter_condition_by_min_quantity($further_coupon);
				return $this->filter_condition_by_full_gift($further_coupon)&& $condition_by_membership_ids && $condition_by_first_purchase && $condition_by_min_quantity;
			}
			);
		// 過濾掉空值，並重新索引陣列
		$further_coupons = array_values(
			array_filter(
			$further_coupons,
			function ( $value ) {
				return !empty($value); // 過濾掉空值
			}
			)
			);
		// 只保留前 N 個 further_coupons
		$show_further_coupons_qty = (int) $power_plugins_settings[ Settings::SHOW_FURTHER_COUPONS_QTY_FIELD_NAME ] ?? 3;
		$sliced_further_coupons   = array_slice($further_coupons, 0, $show_further_coupons_qty);

		// 如果啟用只顯示最大折扣券
		if ($power_plugins_settings[ Settings::ENABLE_BIGGEST_COUPON_FIELD_NAME ]) {
			// 如果沒有可用優惠券，則直接返回進一步的優惠券
			if (empty($available_coupons)) {
				return $sliced_further_coupons;
			}
			$result = array_merge([ $available_coupons[0] ], $sliced_further_coupons);
		} else {
			$result = array_merge($available_coupons, $sliced_further_coupons);
		}
		return $result;
	}

	/**
	 * 獲取優惠券金額
	 *
	 * @param \WC_Coupon $coupon 優惠券
	 * @return int
	 */
	public function get_coupon_amount( \WC_Coupon $coupon ): int {
		if ($coupon->is_type([ 'percent' ])) {
			$cart = WC()->cart;
			// 調整以避免出現浮點數問題，並將其四捨五入
			return (int) round( $coupon->get_amount() * $cart->subtotal / 100);
		}
		return (int) $coupon->get_amount();
	}

	/**
	 * 獲取最大折扣券
	 *
	 * @param array $coupons 優惠券數組
	 * @return \WC_Coupon|null
	 */
	public function get_biggest_coupon( array $coupons ): ?\WC_Coupon {
		// 初始化最大折扣金額
		$max_discount_amount = 0;
		// 初始化最大折扣券对象
		$max_discount_coupon = null;

		// 遍历优惠券数组
		foreach ($coupons as $coupon) {
			// 获取折扣金额
			$discount_amount = $coupon->get_amount();

			// 检查是否是固定折扣券或百分比折扣券
			// 这里简单地根据折扣金额的正负来判断，你可能需要根据实际情况调整
			if ($discount_amount > $max_discount_amount) {
				// 更新最大折扣金额和对应的折扣券对象
				$max_discount_amount = $discount_amount;
				$max_discount_coupon = $coupon;
			}
		}

		return $max_discount_coupon;
	}

	/**
	 * 獲取優惠券屬性
	 *
	 * @param \WC_Coupon $coupon 優惠券
	 * @return array
	 */
	public function get_coupon_props( \WC_Coupon $coupon ): array {
		if (empty($coupon)) {
			return [];
		}
		$cart_total     = (int) WC()->cart->subtotal;
		$coupon_amount  = (int) $coupon->get_amount();
		$minimum_amount = (int) $coupon->get_minimum_amount();

		$props = [];
		if ($cart_total < $minimum_amount) {

			$d                     = $minimum_amount - $cart_total;
			$shop_url              = site_url('shop');
			$props['is_available'] = false;
			$props['reason']       = "，<span class='text-red-400'>還差 ${d} 元</span>，<a href='${shop_url}'>再去多買幾件 》</a>";
			$props['disabled']     = 'disabled';
			$props['disabled_bg']  = 'bg-gray-100 cursor-not-allowed';
			return $props;
		} else {
			$props['is_available'] = true;
			$props['reason']       = '';
			$props['disabled']     = '';
			$props['disabled_bg']  = '';
			return $props;
		}
	}

	/**
	 * 獲取用戶訂單數量
	 *
	 * @param int $user_id 用戶 ID
	 * @return int
	 */
	public static function get_order_quantity_by_user( int $user_id = 0 ): int {
		if (empty($user_id)) {
			$user_id = \get_current_user_id();
		}
		global $wpdb;
		$query = $wpdb->prepare("SELECT COUNT(ID) FROM {$wpdb->prefix}posts WHERE post_type = 'shop_order' AND post_status IN ('wc-completed', 'wc-processing') AND post_author = %d", $user_id);

		$order_count = (int) $wpdb->get_var($query); // phpcs:ignore

		return $order_count;
	}

	/**
	 * 是否首次購買
	 *
	 * @param int $user_id 用戶 ID
	 * @return bool
	 */
	private function is_first_purchase( int $user_id = 0 ): bool {
		if (empty($user_id)) {
			$user_id = \get_current_user_id();
		}
		$count = self::get_order_quantity_by_user($user_id);

		return $count === 0;
	}

	/**
	 * 修改最小金額條件
	 *
	 * @param bool       $not_valid 是否無效
	 * @param \WC_Coupon $coupon 優惠券
	 * @param int        $subtotal 小計
	 * @return bool
	 */
	public function modify_minimum_amount_condition( bool $not_valid, \WC_Coupon $coupon, int $subtotal ): bool {
		global $power_plugins_settings;

		if ($power_plugins_settings[ Settings::ENABLE_SHOW_FURTHER_COUPONS_FIELD_NAME ] && $not_valid) {
			// 判斷further_coupons中是否存在相同的coupon
			$this->further_coupons[ $coupon->get_id() ] = $coupon;
		}

		return $not_valid;
	}

	/**
	 * Checkout 頁面操作折抵購物金
	 *
	 * @return void
	 */
	public function award_deduct_point(): void {
		$value     = (int) $_POST['value']; // phpcs:ignore
		$coupon_id = (int) $_POST['coupon_id']; // phpcs:ignore
		if (!\is_user_logged_in()) {
			\wp_send_json_error('請先登入');
		}

		if (!$value || !$coupon_id) {
			\wp_send_json_error('請輸入折抵的數值');
		}

		$user_id    = \get_current_user_id();
		$user_point = \gamipress_get_user_points($user_id, 'ee_point');

		if ($user_point < $value) {
			\wp_send_json_error('購物金不足');
		}

		$sub_total = (int) WC()->cart->subtotal;
		$coupon    = new \WC_Coupon($coupon_id);

		$deduct_ratio      = $coupon->get_amount() / 100;
		$max_deduct_amount = \floor($sub_total * $deduct_ratio);

		if ($value > $max_deduct_amount) {
			\wp_send_json_error('購物金折抵金額超過上限');
		}

		\WC()->session->set(
			'custom_fee',
			[
				'amount'    => -$value,
				'coupon_id' => $coupon_id,
			]
			);
		// 觸發購物車重新計算
		\WC()->cart->calculate_totals();

		\wp_send_json_success(
			[
				'updated_user_point' => $user_point - $value,
			]
			);
	}

	/**
	 * 添加自訂費用
	 */
	public function add_custom_fee(): void {
		$value             = WC()->session->get('custom_fee');
		$point_amount      = $value['amount'] ?? 0;
		$coupon_id         = $value['coupon_id'] ?? 0;
		$coupon            = new \WC_Coupon($coupon_id);
		$sub_total         = (int) WC()->cart->subtotal;
		$deduct_ratio      = $coupon->get_amount() / 100;
		$max_deduct_amount = \floor($sub_total * $deduct_ratio);

		if ($point_amount > $max_deduct_amount) {
			$point_amount = $max_deduct_amount;
		}

		if ($value && \is_checkout()) {
			\WC()->cart->add_fee(
				'購物金折抵',
					$point_amount,
					'yes' === get_option('woocommerce_calc_taxes')?true:false
			);
		}
	}

	/**
	 * 執行扣除點數
	 *
	 * @param \WC_Order $order 訂單
	 */
	public function exec_deduct_point( \WC_Order $order ): void {
		$user_id      = $order->get_customer_id();
		$value        = WC()->session->get('custom_fee');
		$point_amount = $value['amount'] ?? 0;
		if (!$point_amount) {
			return;
		}

		$order->update_meta_data('award_deduct_point', $point_amount);
		$order->save();

		$updated_user_point = \gamipress_deduct_points_to_user(
			$user_id,
			(int) $point_amount,
			'ee_point',
			[
				'admin_id'       => 0,
				'achievement_id' => null,
				'reason'         => "使用購物金折抵 {$point_amount} 元",
				'log_type'       => 'points_deduct',
			]
			);

		\WC()->session->__unset('custom_fee');
	}

	/**
	 * 清除購物車和會話
	 */
	public function clear_cart_and_session(): void {
		\WC()->session->set('custom_fee', null);
	}

	/**
	 * 清除自訂費用
	 */
	public function clear_fee(): void {
		if (isset($_GET['remove_item'])) {
			\WC()->session->set('custom_fee', null);
		}
	}

	/**
	 * 恢復購物金折抵點數
	 *
	 * @param int $order_id 訂單 ID
	 */
	public function restore_award_deduct_point( int $order_id ): void {
		$order        = \wc_get_order($order_id);
		$deduct_point = (int) $order->get_meta('award_deduct_point');
		$award_point  = $deduct_point * -1;
		$user_id      = $order->get_customer_id();
		\gamipress_award_points_to_user(
			$user_id,
			(int) $award_point,
			'ee_point',
			[
				'admin_id'       => 0,
				'achievement_id' => null,
				'reason'         => "歸還購物金折抵 {$deduct_point} 元，訂單 #{$order_id} 取消",
				'log_type'       => 'points_award',
			]
		);
	}
	/**
	 * 根據傳入的shipping class id 傳回 slug
	 *
	 * @param array $shipping_class_id
	 * return array
	 */
	public function get_shipping_classes_slug( $shipping_class_ids ): array {
		$shipping_classes = \WC()->shipping->get_shipping_classes();
		$slug             = [];
		foreach ($shipping_class_ids as $shipping_class_id) {
			foreach ($shipping_classes as $shipping_class) {
				if ( $shipping_class_id === (string) $shipping_class->term_id) {
					$slug[] = $shipping_class->slug;
				}
			}
		}
		return $slug;
	}
	// public function add_fee(\WC_Cart $cart): void
	// {
	// $cart->add_fee(__("首次消費折 {$discount} 元", 'power_membership'), -$discount);
	// }

	/**
	 * 是否使用過某種優惠券
	 *
	 * @param int        $user_id 用戶 ID
	 * @param \WC_Coupon $coupon_check 優惠券
	 * @param string     $date 日期
	 *
	 * @return bool
	 */
	public function has_user_used_coupon( $user_id, $coupon_check, $date ) {
		if (!$user_id) {
			return false; // 如果是訪客則無法檢查
		}
		// 計算當天往前三個月的日期區間
		$current_date = wp_date('Y-m-d H:i:s');
		$start_date   = wp_date('Y-m-d 00:00:00', strtotime('-3 months', strtotime($current_date)));

		$args = [
			'customer_id'  => $user_id,
			'status'       => [ 'wc-completed', 'wc-processing', 'wc-on-hold' ], // 只查詢有效的訂單
			'limit'        => -1, // 確保查詢所有符合條件的訂單
			'date_created' => $start_date . '...' . $current_date, // 三個月前到現在
		];

		$orders = wc_get_orders($args);
		foreach ($orders as $order) {
			$coupons = $order->get_coupons(); // WooCommerce 8.5+ 推薦使用此方法
			foreach ($coupons as $coupon) {
				// 如果找到符合的折價券，表示已經使用過
				if ($coupon->get_code()===$coupon_check->get_code()) {
					return true; // 找到符合的折價券，表示已經使用過
				}
			}
		}
		return false;
	}
}


View::instance();
