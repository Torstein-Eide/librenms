<?php

namespace LibreNMS\Agent\Module\Smart;

use LibreNMS\Agent\Application;

/**
 * SMART application dispatcher.
 *
 * Reads the payload version and delegates to the correct handler:
 *   version >= 2  →  SmartV2 (agent JSON, full sensor/RRD pipeline)
 *   version < 2   →  SmartV1 (legacy CSV or v1 JSON, raw RRD only)
 *
 * The version used for discovery is read from persisted app data written by
 * the previous poll cycle, so discover() and poll() always agree on the handler.
 */
class Common extends Application
{
    public function shouldDiscover(): bool
    {
        $version = $this->app->data['version'] ?? 1;

        return $version >= 2;
    }

    public function discover(): void
    {
        $version = $this->app->data['version'] ?? 1;

        if ($version >= 2) {
            $handler = new SmartV2($this->os, $this->app, $this->agent_data);
            $handler->discover();
        }
    }

    public function shouldPoll(): bool
    {
        return true;
    }

    public function poll(): void
    {
        $version = $this->agent_data['version'] ?? 1;

        if ($version >= 2) {
            $handler = new SmartV2($this->os, $this->app, $this->agent_data);
        } else {
            $handler = new SmartV1($this->os, $this->app, $this->agent_data);
        }

        $handler->poll();
    }
}
