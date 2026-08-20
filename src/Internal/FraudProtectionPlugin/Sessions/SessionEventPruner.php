<?php
/**
 * SessionEventPruner class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;

defined( 'ABSPATH' ) || exit;

/**
 * Prunes old rows from the sessions log via a daily Action Scheduler job.
 *
 * Retention is fixed at 30 days for the MVP, matching the Alpha client-side
 * retention target ("30 days for attempt data and events"); configurability
 * is deferred to post-MVP.
 */
class SessionEventPruner {

	/**
	 * Action Scheduler hook name for the recurring pruning job.
	 */
	public const PRUNE_ACTION_HOOK = 'wc_fraud_protection_prune_sessions';

	/**
	 * Retention period for session event rows, in days.
	 */
	public const RETENTION_DAYS = 30;

	/**
	 * Action Scheduler group for the plugin's actions.
	 */
	private const ACTION_GROUP = 'woocommerce-fraud-protection';

	/**
	 * Session event store instance.
	 *
	 * @var SessionEventStore
	 */
	private SessionEventStore $event_store;

	/**
	 * Merchant lists feature gate instance.
	 *
	 * @var MerchantListsFeature
	 */
	private MerchantListsFeature $merchant_lists_feature;

	/**
	 * Fraud Protection logger instance.
	 *
	 * @var FraudProtectionLogger
	 */
	private FraudProtectionLogger $logger;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionEventStore     $event_store            The session event store instance.
	 * @param MerchantListsFeature  $merchant_lists_feature The merchant lists feature gate instance.
	 * @param FraudProtectionLogger $logger                 The logger instance.
	 */
	final public function init( SessionEventStore $event_store, MerchantListsFeature $merchant_lists_feature, FraudProtectionLogger $logger ): void {
		$this->event_store            = $event_store;
		$this->merchant_lists_feature = $merchant_lists_feature;
		$this->logger                 = $logger;
	}

	/**
	 * Register the pruning action and reconcile its schedule.
	 *
	 * The callback is always registered so queued actions never go unhandled.
	 * Rescheduling of each next daily run is Action Scheduler's own job (it
	 * perpetuates recurring actions when processing them); reconciliation here
	 * only covers the first schedule (and the teardown, should the feature gate
	 * ever be turned off in code). It runs on admin requests only, not on
	 * frontend ones, to avoid an Action Scheduler lookup on every page load:
	 * with a 30-day retention window, waiting for the first admin request to
	 * schedule the job is harmless.
	 */
	public function register(): void {
		add_action( self::PRUNE_ACTION_HOOK, array( $this, 'handle_wc_fraud_protection_prune_sessions' ) );

		if ( is_admin() ) {
			$this->reconcile_schedule();
		}
	}

	/**
	 * Prune session event rows older than the retention period.
	 *
	 * Callback for the recurring Action Scheduler job.
	 *
	 * @internal
	 */
	public function handle_wc_fraud_protection_prune_sessions(): void {
		if ( ! $this->merchant_lists_feature->is_enabled() ) {
			return;
		}

		try {
			$deleted = $this->event_store->prune_older_than( self::RETENTION_DAYS );

			if ( $deleted > 0 ) {
				$this->logger->log(
					'info',
					sprintf( 'Pruned %d session event(s) older than %d days.', $deleted, self::RETENTION_DAYS )
				);
			}
		} catch ( \Throwable $e ) {
			$this->logger->log(
				'warning',
				'Session event pruning failed',
				array(
					'event_source'      => 'session_event_pruner',
					'exception'         => $e,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
				),
				true
			);
		}
	}

	/**
	 * Schedule the daily pruning job when the feature is enabled, unschedule
	 * it when it is not.
	 */
	private function reconcile_schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$enabled   = $this->merchant_lists_feature->is_enabled();
		$scheduled = false !== as_next_scheduled_action( self::PRUNE_ACTION_HOOK, array(), self::ACTION_GROUP );

		if ( $enabled && ! $scheduled ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::PRUNE_ACTION_HOOK, array(), self::ACTION_GROUP, true );
		} elseif ( ! $enabled && $scheduled ) {
			as_unschedule_all_actions( self::PRUNE_ACTION_HOOK, array(), self::ACTION_GROUP );
		}
	}
}
