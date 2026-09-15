// Configuration injected by the server for the settings app (see
// FraudProtectionSettingsPage::inject_settings_config).

export type FraudProtectionSettingsConfig = {
	// Whether the current user has dismissed the automatic-protection notice on
	// the settings pane.
	automaticProtectionNoticeDismissed: boolean;
	// Whether the current user has dismissed the "protection off" banner on the
	// checkout attempts list.
	checkoutAttemptsBannerDismissed: boolean;
};

declare global {
	interface Window {
		wcFraudProtectionSettings?: Partial< FraudProtectionSettingsConfig >;
	}
}

export function getSettingsConfig(): FraudProtectionSettingsConfig {
	const config = window.wcFraudProtectionSettings ?? {};

	return {
		automaticProtectionNoticeDismissed:
			true === config.automaticProtectionNoticeDismissed,
		checkoutAttemptsBannerDismissed:
			true === config.checkoutAttemptsBannerDismissed,
	};
}
