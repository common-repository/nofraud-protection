<?php

namespace WooCommerce\NoFraud\Payment\Transactions;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use WooCommerce\NoFraud\Common\Environment;

final class Transaction_Renderer {

	/**
	 * Transaction decision markup, allowed HTML.
	 *
	 * @var array Transaction decision markup, allowed HTML.
	 */
	const TRANSACTION_DECISION_MARKUP_ALLOWED_HTML = [
		'a' => [
			'href' => [],
			'target' => [],
		],
		'span' => [
			'style' => [],
		],
	];

	/**
	 * Transaction decisions settings.
	 *
	 * Available settings:
	 *   'color'   - The status's color code.
	 *   'message' - The status's message.
	 *
	 * @var array Transaction decisions settings.
	 */
	const TRANSACTION_DECISION_SETTINGS = [
		'pass' => [
			'color' => '#36B37E',
			'message' => 'PASS',
		],
		'review' => [
			'color' => '#FF991F',
			'message' => 'UNDER REVIEW',
		],
		'fail' => [
			'color' => '#FF5630',
			'message' => 'FAILED',
		],
		'fraudulent' => [
			'color' => '#FF5630',
			'message' => 'FRAUDULENT',
		],
		'error' => [
			'color' => '#777777',
			'message' => 'ERROR',
		],
		'unknown' => [
			'color' => '#777777',
			'message' => 'UNKNOWN',
		],
        'N/A' => [
            'color' => '#C2C2C2',
            'message' => 'N/A',
        ],
        'disabled' => [
            'color' => '#C2C2C2',
            'message' => 'DISABLED',
        ],
        'skipped' => [
            'color' => '#ffe71f',
            'message' => 'SKIPPED',
        ],
        'allowed' => [
            'color' => '#36B37E',
            'message' => 'ALLOWED',
        ],
        'blocked' => [
            'color' => '#FF5630',
            'message' => 'BLOCKED',
        ],
	];


	/**
	 * Registers the class's hooks and actions with WordPress.
	 */
	public static function register() {
		$instance = new self();

		// Add safe css styles to allow properly rendered transaction links.
		add_action('safe_style_css', [$instance, 'add_safe_style_css']);
	}

	/**
	 * Add safe css styles (to allow properly rendered transaction links).
	 *
	 * @param array $style Safe CSS styles.
	 * @return array Safe CSS styles.
	 *
	 * @since 2.1.1
	 */
	public function add_safe_style_css( $styles ) {
		$styles[] = 'display';
		return $styles;
	}

	/**
	 * Get NoFraud transaction decision markup string.
	 *
	 * @param stdObject $transaction_review NoFraud transaction review.
	 * @return string NoFraud transaction status markup.
	 *
	 * @since 2.0.0
	 */
	public static function get_transaction_decision_markup( $transaction_review ) {
		// Render decision on the order status page.
		$transaction_review_id = empty($transaction_review->id) ? null : $transaction_review->id;
		$transaction_review_decision = empty($transaction_review->decision) ? 'unknown' : $transaction_review->decision;
		
		//override decision for skipped overrides
        if (isset($transaction_review->override) && 'true' === $transaction_review->override) {
            if(isset($transaction_review->override_type) && 'allow' === $transaction_review->override_type) {
                $transaction_review_decision = 'allowed';
            }
            else if(isset($transaction_review->override_type) && 'block' === $transaction_review->override_type) {
                $transaction_review_decision = 'blocked';
            }
            else {
                $transaction_review_decision = 'skipped';
            }
        }
		
		$decision_message_link = self::get_transaction_link($transaction_review_id, $transaction_review_decision);
		$decision_message_color = self::get_transaction_color($transaction_review_decision);

		return '<span style="height:8px;width:8px;border-radius:35%;display:inline-block;background-color:' . $decision_message_color . ';"></span> ' . $decision_message_link;
	}

	/**
	 * Get NoFraud transaction order note.
	 *
	 * @param string $transaction_review_id Transaction review UUID.
	 * @param string $transaction_decision Transaction decision.
	 * @return string NoFraud transaction order note.
	 *
	 * @since 2.0.0
	 */
	public static function get_transaction_order_note( $transaction_review_id, $transaction_decision ) {
		// translators: transaction link.
		return sprintf(__('NoFraud rendered a result of %s.', 'nofraud-protection'), self::get_transaction_link($transaction_review_id, $transaction_decision));
	}

	/**
	 * Get NoFraud transaction link.
	 *
	 * @param string $transaction_review_id Transaction review UUID.
	 * @param string $transaction_decision Transaction decision.
	 * @return string Link to a NoFraud Portal transaction page with the decision message.
	 */
	private static function get_transaction_link( $transaction_review_id, $transaction_decision ) {
		if (empty(self::TRANSACTION_DECISION_SETTINGS[$transaction_decision]['message'])) {
			return __(self::TRANSACTION_DECISION_SETTINGS['unknown']['message'], 'nofraud-protection');
		}

		$decision_message = esc_html(__(self::TRANSACTION_DECISION_SETTINGS[$transaction_decision]['message'], 'nofraud-protection'));
		if (empty($transaction_review_id)) {
			return $decision_message;
		}

		$decision_message_link = esc_url(Environment::get_service_url('portal') . '/transaction/' . $transaction_review_id);
		return '<a href="' . $decision_message_link . '" target="_blank">' . $decision_message . '</a>';
	}

	/**
	 * Get NoFraud transaction color.
	 *
	 * @param string $transaction_decision Transaction decision.
	 * @return string Color of a NoFraud Portal transaction decision.
	 */
	private static function get_transaction_color( $transaction_decision ) {
		if (!isset(self::TRANSACTION_DECISION_SETTINGS[$transaction_decision]['color'])) {
			return self::TRANSACTION_DECISION_SETTINGS['unknown']['color'];
		}
		return self::TRANSACTION_DECISION_SETTINGS[$transaction_decision]['color'];
	}

	/**
	 * Get NoFraud cancel transaction order note.
	 *
     * @param stdObject|false $transaction_review NoFraud transaction review.
     * @param string $cancel_type Transaction cancellation type defined in Transaction_Manager
	 * @return string NoFraud cancel transaction order note.
	 *
	 * @since 2.1.4
	 */
	public static function get_cancel_transaction_order_note($transaction_review, $cancel_type) {
	    if($cancel_type === Transaction_Manager::CANCEL_TRANSACTION_TYPES['ADDRESSCHANGE']) {
            return __('After Order Address change, previous NoFraud transaction (' . $transaction_review->id . ') is automatically marked as cancelled and new NoFraud transaction is being created.', 'nofraud-protection');
        }
		return __('After Order status change, NoFraud transaction is automatically marked as cancelled.', 'nofraud-protection');
	}
    
    /**
     * Get NoFraud update transaction order note.
     *
     * @param stdObject|false $transaction_review NoFraud transaction review.
     * @param string $update_type Transaction update type defined in Transaction_Manager
     * @return string NoFraud update transaction order note.
     *
     * @since 3.0.0
     */
    public static function get_update_transaction_order_note($old_transaction_review, $new_transaction_review, $update_type = '') {
        if($update_type === Transaction_Manager::UPDATE_TRANSACTION_TYPES['SHIPPINGADDRESSCHANGE']) {
            if(!empty($new_transaction_review->id)) {
                return __('After Order Shipping Address change, previous NoFraud transaction (' . $old_transaction_review->id . ') decision has been replaced by (' . $new_transaction_review->id . ').', 'nofraud-protection');
            }
            return __('After Order Shipping Address change, previous NoFraud transaction (' . $old_transaction_review->id . ') decision has been cancelled.', 'nofraud-protection');
        }
        return __('After Order Shipping Address change, previous NoFraud transaction (' . $old_transaction_review->id . ') decision has been replaced by (' . $new_transaction_review->id . ').', 'nofraud-protection');
    }
    
    /**
     * Get NoFraud order note when payment gateway is not enabled
     *
     * @return string NoFraud order note.
     *
     * @since 3.0.0
     */
    public static function get_disabled_gateway_order_note() {
        return __('NoFraud did not screen this transaction because the payment gateway used was disabled in the settings.', 'nofraud-protection');
    }
}

Transaction_Renderer::register();
