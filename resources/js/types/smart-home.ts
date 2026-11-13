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

export type DeviceKind =
    | 'light'
    | 'switch'
    | 'lock'
    | 'temperature'
    | 'humidity'
    | 'door'
    | 'window'
    | 'motion'
    | 'sensor'
    | 'binary_sensor'
    | 'cover'
    | 'climate'
    | 'fan';

export type Device = {
    id: string;
    entity_id: string;
    name: string;
    kind: DeviceKind;
    room: string | null;
    room_id: string | null;
    state: string | null;
    state_label: string;
    on: boolean;
    value: number | null;
    unit: string | null;
    brightness: number | null;
    available: boolean;
    imported: boolean;
    capabilities: {
        switchable: boolean;
        dimmable: boolean;
        lockable: boolean;
        reading: boolean;
        contact: boolean;
        motion: boolean;
    };
    last_changed_at: string | null;
};

export type Room = { id: string; name: string };

/** App\SmartHome\HomeSummary */
export type HomeSummary = {
    connected: boolean;
    status: ConnectionStatus | null;
    lights_on: number;
    lights: number;
    open: string[];
    motion: string[];
    unlocked: string[];
    temperature: { min: number; max: number } | null;
    humidity: { max: number; room: string | null } | null;
    unavailable: string[];
};
