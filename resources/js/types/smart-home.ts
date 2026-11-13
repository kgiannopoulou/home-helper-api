/** The Smart home page (app/Http/Controllers/Web/SmartHomeController.php) */

export type ConnectionStatus =
    | 'connected'
    | 'unreachable'
    | 'unauthorized'
    | 'error';

export type SmartHomeConnection = {
    base_url: string;
    status: ConnectionStatus;
    status_message: string | null;
    location_name: string | null;
    ha_version: string | null;
    last_ok_at: string | null;
    last_event_at: string | null;
    last_synced_at: string | null;
    webhook_url: string;
};

/** Shown once, after connecting or rotating the secret */
export type WebhookSecret = { url: string; token: string };
