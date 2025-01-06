<?php
/**
 * 會員等級 Metabox
 */

declare(strict_types=1);

namespace J7\PowerMembership\MemberLv;

use J7\PowerMembership\Utils\Base;
use J7\PowerMembership\Plugin;

/**
 * 會員等級 Metabox
 */
final class Metabox {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 預設會員等級 ID
	 *
	 * @var int
	 */
	public static $default_member_lv_id;
	const ACTION             = 'power_membership_metabox';
	const THRESHOLD_META_KEY = 'power_membership_threshold';
	const VALIDITY_PERIOD    = 'power_membership_validity_period';

	/**
	 * 建構子
	 */
	public function __construct() {
		\add_action('add_meta_boxes', [ $this, 'add_metabox' ], 10);
		\add_action('save_post', [ $this, 'save_metabox' ], 10, 2);

		// 會重複創建是因為在 init 後才會有 post type,改成在wp_loaded 後執行
		// \add_action('init', [ $this, 'create_default_member_lv' ], 30);
		\add_action('wp', [ $this, 'create_default_member_lv' ], 30);
	}

	/**
	 * 添加 Metabox
	 */
	public function add_metabox(): void {
		\add_meta_box(
			Plugin::$snake . '_metabox',
			'會員升級門檻',
			[ $this, 'render_metabox' ],
			Base::MEMBER_LV_POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * 渲染 Metabox
	 *
	 * @param \WP_Post $post 文章
	 */
	public function render_metabox( \WP_Post $post ): void {
		$threshold       = \get_post_meta($post->ID, self::THRESHOLD_META_KEY, true);
		$threshold       = empty($threshold) ? 0 : (int) $threshold;
		$validity_period = \get_post_meta($post->ID, self::VALIDITY_PERIOD, true);
		$validity_period = empty($validity_period) ? 12 : (int) $validity_period;
		?>
		<div class="tailwindcss">
			<div class="flex items-center tailwind">
				<label for="<?php echo self::THRESHOLD_META_KEY; ?>" class="w-[14rem] block">會員累積消費升級門檻</label>
				<input type="number" value="<?php echo $threshold; ?>" name="<?php echo self::THRESHOLD_META_KEY; ?>" min="0" step="1000" class="ml-8" />
			</div>
			<div class="flex items-center tailwind">
				<label for="<?php echo self::VALIDITY_PERIOD; ?>" class="w-[14rem] block">會員會籍效期</label>
				<input type="number" value="<?php echo $validity_period; ?>" name="<?php echo self::VALIDITY_PERIOD; ?>" min="0" step="1" class="ml-8" />
				<span class="ml-2">月</span>
			</div>
		</div>
		<?php
	}

	/**
	 * 保存 Metabox
	 *
	 * @param int      $post_id 文章 ID
	 * @param \WP_Post $post 文章
	 */
	public function save_metabox( int $post_id, \WP_Post $post ): void {
		// Check if user has permissions to save data.
		if (!\current_user_can('edit_post', $post_id)) {
			return;
		}
		// Check if not an autosave.
		if (\defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		// 如果非member_lv post type,則不處理
		if ($post->post_type !== Base::MEMBER_LV_POST_TYPE) {
			return;
		}
		$threshold_value = isset($_POST[ self::THRESHOLD_META_KEY ]) ? \sanitize_text_field($_POST[ self::THRESHOLD_META_KEY ]) : 0; //phpcs:ignore
		$threshold_value       = is_numeric($threshold_value) ? $threshold_value : 0;
		$validity_period_value = isset($_POST[ self::VALIDITY_PERIOD ]) ? \sanitize_text_field($_POST[ self::VALIDITY_PERIOD ]) : 12; //phpcs:ignore
		$validity_period_value = is_numeric($validity_period_value) ? $validity_period_value : 12;
		\update_post_meta($post_id, self::THRESHOLD_META_KEY, $threshold_value);
		\update_post_meta($post_id, self::VALIDITY_PERIOD, $validity_period_value);
	}


	/**
	 * 創建會員等級文章類型
	 */
	private function create_member_lv_post_type(): void {
		$post_type = Base::MEMBER_LV_POST_TYPE;
		if (\post_type_exists($post_type)) {
			return;
		}

		// create member_lv Rank Type in Gamipress
		\wp_insert_post(
			[
				'post_title'  => '會員等級',
				'post_type'   => 'rank-type',
				'post_status' => 'publish',
				'post_name'   => Base::MEMBER_LV_POST_TYPE,
				'meta_input'  => [
					'_gamipress_plural_name' => '會員等級',
				],
			]
		);
	}

	/**
	 * 創建預設會員等級
	 */
	public function create_default_member_lv(): void {
		$post_type = Base::MEMBER_LV_POST_TYPE;

		if (!\post_type_exists($post_type)) {
			$this->create_member_lv_post_type();
		}

		$slug = 'default';

		$page = get_page_by_path($slug, OBJECT, $post_type);
		if ($page) {
			self::$default_member_lv_id = $page->ID;
			return;
		} else {

			// create default member_lv
			$post_id                    = \wp_insert_post(
				[
					'post_title'  => '預設會員',
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'post_name'   => $slug,
					'meta_input'  => [
						self::THRESHOLD_META_KEY => '0',
						self::VALIDITY_PERIOD    => '12',
					],
				]
			);
			self::$default_member_lv_id = $post_id;
			$this->set_all_users_default_member_lv($post_id);
		}
	}

	/**
	 * 設置所有用戶的預設會員等級
	 *
	 * @param int $default_member_lv_id 預設會員等級 ID
	 */
	private function set_all_users_default_member_lv( int $default_member_lv_id ): void {
		global $wpdb;
		$prefix = $wpdb->prefix;
		// get all user ids
		$user_ids = $wpdb->get_col("SELECT ID FROM {$prefix}users"); //phpcs:ignore

		foreach ($user_ids as $user_id) {
			$member_lv = \get_user_meta($user_id, Base::CURRENT_MEMBER_LV_META_KEY);
			if (empty($member_lv)) {
				\update_user_meta($user_id, Base::CURRENT_MEMBER_LV_META_KEY, $default_member_lv_id);
			}
		}
	}
}

Metabox::instance();
