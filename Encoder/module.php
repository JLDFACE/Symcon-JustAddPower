<?php

class JAPMaxColorEncoderFlexible extends IPSModule
{
    private $TX = "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}";

    public function Create()
    {
        parent::Create();

        $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");

        $this->RegisterPropertyString("Host", "192.168.10.50");
        $this->RegisterPropertyInteger("Port", 23);
        $this->RegisterPropertyBoolean("UseCRLF", true);

        $this->RegisterPropertyInteger("RegistryInstanceID", 0);

        $this->RegisterPropertyString("SourceName", "");

        $this->RegisterPropertyInteger("VideoChannel", 0);
        $this->RegisterPropertyInteger("AudioChannel", 0);
        $this->RegisterPropertyInteger("USBChannel", 0);

        $this->RegisterPropertyBoolean("AutoAssignFromSchema", true);
        $this->RegisterPropertyBoolean("AutoApplyChannelsOnApply", false);

        $this->RegisterVariableBoolean("Online", "Online", "~Alert.Reversed", 1);
        IPS_SetIcon($this->GetIDForIdent("Online"), "Network");

        $this->RegisterVariableBoolean("HDMISignal", "HDMI Signal", "~Alert.Reversed", 2);
        IPS_SetIcon($this->GetIDForIdent("HDMISignal"), "TV");

        $this->RegisterVariableString("LastResponse", "Last Response", "", 90);

        $this->RegisterAttributeBoolean("WasOnline", false);
        $this->RegisterAttributeString("LastAppliedState", "");
        $this->RegisterAttributeBoolean("AutoApplySafetyMigrated", false);

        $this->RegisterTimer("OnlineCheckTimer", 30000, 'JAPMC_CheckOnlineStatus($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Safety migration: previously auto-apply defaulted to true and could overwrite live channels.
        // After this update we default to false and disable existing instances once.
        if (!(bool)$this->ReadAttributeBoolean("AutoApplySafetyMigrated")) {
            $this->WriteAttributeBoolean("AutoApplySafetyMigrated", true);
            if ((bool)$this->ReadPropertyBoolean("AutoApplyChannelsOnApply")) {
                IPS_SetProperty($this->InstanceID, "AutoApplyChannelsOnApply", false);
                IPS_ApplyChanges($this->InstanceID);
                $this->SendDebug("JAPMC ENC Safety", "AutoApplyChannelsOnApply disabled by migration", 0);
                return;
            }
        }

        $host = (string)$this->ReadPropertyString("Host");
        $port = (int)$this->ReadPropertyInteger("Port");

        $inst = IPS_GetInstance($this->InstanceID);
        $parentID = isset($inst["ConnectionID"]) ? (int)$inst["ConnectionID"] : 0;

        if ($parentID > 0 && IPS_InstanceExists($parentID)) {
            IPS_SetProperty($parentID, "Host", $host);
            IPS_SetProperty($parentID, "Port", $port);

            // Auto-Open nur wenn TCP erreichbar (Port 23)
            $canConnect = $this->TestTcp($host, $port, 250);
            $this->SendDebug("JAPMC ENC", "AutoOpen=" . ($canConnect ? "true" : "false") . " for " . $host . ":" . $port, 0);

            // Open setzen (versions-/property-sicher: Errors abfangen)
            $this->CallSilenced(function () use ($parentID, $canConnect) {
                IPS_SetProperty($parentID, "Open", $canConnect);
            });

            // Parent anwenden (Warnings/Errors abfangen, damit Instanz-Erstellung nie scheitert)
            $this->CallSilenced(function () use ($parentID) {
                IPS_ApplyChanges($parentID);
            });
        }

        if ($this->ReadPropertyBoolean("AutoAssignFromSchema")) {
            $this->AutoAssignIfNeeded();
        }

        $this->CheckOnlineStatus();
        $this->ApplyChannelsIfNeeded("ApplyChanges");

        $this->SetStatus(102);
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data["Buffer"])) return;

        $buffer = trim((string)$data["Buffer"]);
        if ($buffer !== "") {
            $this->SendDebug("JAPMC ENC RX", $buffer, 0);

            // Shell-Prompts und leere Antworten ignorieren
            if ($this->IsRelevantResponse($buffer)) {
                SetValueString($this->GetIDForIdent("LastResponse"), $buffer);
            }
        }
    }

    public function CheckOnlineStatus()
    {
        $host = (string)$this->ReadPropertyString("Host");
        $port = (int)$this->ReadPropertyInteger("Port");

        $isOnline = $this->TestTcp($host, $port, 1000);
        $wasOnline = (bool)$this->ReadAttributeBoolean("WasOnline");

        SetValueBoolean($this->GetIDForIdent("Online"), $isOnline);

        // Reconnect: War offline, ist jetzt online -> Parent-Verbindung öffnen
        if (!$wasOnline && $isOnline) {
            $this->SendDebug("JAPMC ENC Reconnect", "Device came online, reconnecting parent", 0);
            $this->ReconnectParent();
            $this->ApplyChannelsIfNeeded("Reconnect");
        }

        // War online, ist jetzt offline -> Parent-Verbindung schließen
        if ($wasOnline && !$isOnline) {
            $this->SendDebug("JAPMC ENC Disconnect", "Device went offline, closing parent", 0);
            $this->DisconnectParent();
        }

        $this->WriteAttributeBoolean("WasOnline", $isOnline);
        $this->SendDebug("JAPMC ENC Online", $isOnline ? "true" : "false", 0);

        if ($isOnline) {
            $this->CheckHDMISignal();
        } else {
            SetValueBoolean($this->GetIDForIdent("HDMISignal"), false);
        }
    }

    public function ApplyChannels()
    {
        $v = (int)$this->ReadPropertyInteger("VideoChannel");
        $a = (int)$this->ReadPropertyInteger("AudioChannel");
        $u = (int)$this->ReadPropertyInteger("USBChannel");

        $this->ApplyChannelSet($v, $a, $u);
        $this->UpdateLastAppliedStateFromProperties();
    }

    public function ApplyVideoChannel()
    {
        $v = (int)$this->ReadPropertyInteger("VideoChannel");
        if ($v <= 0) {
            echo "Ungültiger Video-Kanal: " . $v . "\n";
            echo "Bitte einen gültigen Video-Kanal (> 0) setzen.";
            return;
        }

        $this->ApplySingleChannel("v", $v);
    }

    public function ApplyAudioChannel()
    {
        $a = (int)$this->ReadPropertyInteger("AudioChannel");
        if ($a <= 0) {
            echo "Ungültiger Audio-Kanal: " . $a . "\n";
            echo "Bitte einen gültigen Audio-Kanal (> 0) setzen.";
            return;
        }

        $this->ApplySingleChannel("a", $a);
    }

    public function ApplyUSBChannel()
    {
        $u = (int)$this->ReadPropertyInteger("USBChannel");
        if ($u <= 0) {
            echo "Ungültiger USB-Kanal: " . $u . "\n";
            echo "Bitte einen gültigen USB-Kanal (> 0) setzen.";
            return;
        }

        $this->ApplySingleChannel("u", $u);
    }

    private function AutoAssignIfNeeded()
    {
        $regID = (int)$this->ReadPropertyInteger("RegistryInstanceID");
        if ($regID <= 0 || !IPS_InstanceExists($regID)) return;

        $v = (int)$this->ReadPropertyInteger("VideoChannel");
        $a = (int)$this->ReadPropertyInteger("AudioChannel");
        $u = (int)$this->ReadPropertyInteger("USBChannel");
        if ($v != 0 || $a != 0 || $u != 0) return;

        $n = (int)@JAPMC_RegistryGetNextFreeIndex($regID);
        if ($n < 0) return;

        $videoBase = (int)IPS_GetProperty($regID, "VideoBase");
        $audioBase = (int)IPS_GetProperty($regID, "AudioBase");
        $usbBase   = (int)IPS_GetProperty($regID, "USBBase");

        IPS_SetProperty($this->InstanceID, "VideoChannel", $videoBase + $n);
        IPS_SetProperty($this->InstanceID, "AudioChannel", $audioBase + $n);
        IPS_SetProperty($this->InstanceID, "USBChannel",   $usbBase + $n);
        IPS_ApplyChanges($this->InstanceID);
    }

    public function CheckHDMISignal()
    {
        $host = (string)$this->ReadPropertyString("Host");
        $port = (int)$this->ReadPropertyInteger("Port");
        $crlf = (bool)$this->ReadPropertyBoolean("UseCRLF");

        // Parent kurz trennen – JAP-Geräte erlauben oft nur 1 Telnet-Session gleichzeitig
        $this->DisconnectParent();
        IPS_Sleep(300);

        $response = $this->TelnetExec($host, $port, 2000, 1500, $crlf, "astparam g hdmi_in_det");
        $present  = $this->ParseAstparam($response, "hdmi_in_det");

        SetValueBoolean($this->GetIDForIdent("HDMISignal"), $present);
        $this->SendDebug("JAPMC ENC HDMI", "Signal=" . ($present ? "1" : "0") . " Raw=" . json_encode($response), 0);

        // Parent wieder verbinden
        $this->ReconnectParent();
    }

    private function ParseAstparam($Response, $Key)
    {
        $lines = preg_split('/\r?\n|\r/', (string)$Response);
        foreach ($lines as $line) {
            // ANSI-Escape-Codes und Steuerzeichen entfernen
            $t = preg_replace('/\x1b(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $line);
            $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $t);
            $t = trim($t);
            if ($t === "") continue;

            if (stripos($t, $Key . "=") !== false) {
                $parts = explode("=", $t, 2);
                $val   = trim($parts[1]);
                return ($val === "1" || strtolower($val) === "true" || strtolower($val) === "yes");
            }

            if ($t === "1") return true;
            if ($t === "0") return false;
        }
        return false;
    }

    private function TelnetExec($Host, $Port, $ConnectTimeoutMs, $ReadTimeoutMs, $UseCRLF, $Command)
    {
        $errno = 0; $errstr = "";
        $timeoutSec = max(0.05, ((int)$ConnectTimeoutMs) / 1000.0);

        $fp = @fsockopen($Host, $Port, $errno, $errstr, $timeoutSec);
        if (!is_resource($fp)) return "";

        stream_set_timeout($fp, 0, max(50000, ((int)$ReadTimeoutMs) * 1000));
        @fread($fp, 2048);

        $cmd = $Command . ($UseCRLF ? "\r\n" : "\n");
        @fwrite($fp, $cmd);

        $buf   = "";
        $start = microtime(true);
        $maxSec = max(0.05, ((int)$ReadTimeoutMs) / 1000.0);

        while ((microtime(true) - $start) < $maxSec) {
            $chunk = @fread($fp, 2048);
            if ($chunk === false || $chunk === "") break;
            $buf .= $chunk;
            if (strlen($buf) > 8192) break;
        }

        fclose($fp);
        return $buf;
    }

    private function SendCliCommand($Command)
    {
        $suffix  = $this->ReadPropertyBoolean("UseCRLF") ? "\r\n" : "\n";
        $payload = $Command . $suffix;

        $data = array("DataID" => $this->TX, "Buffer" => $payload);
        $this->SendDataToParent(json_encode($data));
        $this->SendDebug("JAPMC ENC TX", $Command, 0);
    }

    private function ApplyChannelsIfNeeded($Reason, $Force = false)
    {
        if (!$this->ReadPropertyBoolean("AutoApplyChannelsOnApply")) {
            return;
        }

        $v = (int)$this->ReadPropertyInteger("VideoChannel");
        $a = (int)$this->ReadPropertyInteger("AudioChannel");
        $u = (int)$this->ReadPropertyInteger("USBChannel");

        if ($v <= 0 || $a <= 0 || $u <= 0) {
            return;
        }

        $host = (string)$this->ReadPropertyString("Host");
        $port = (int)$this->ReadPropertyInteger("Port");
        $desiredState = $this->BuildApplyState($host, $port, $v, $a, $u);
        $lastState = (string)$this->ReadAttributeString("LastAppliedState");

        if (!$Force && $desiredState === $lastState) {
            return;
        }

        if (!$this->TestTcp($host, $port, 300)) {
            $this->SendDebug("JAPMC ENC Sync", "Skip " . $Reason . " (offline)", 0);
            return;
        }

        $this->ApplyChannelSet($v, $a, $u);
        $this->WriteAttributeString("LastAppliedState", $desiredState);
        $this->SendDebug("JAPMC ENC Sync", "Applied by " . $Reason . ": " . $desiredState, 0);
    }

    private function ApplyChannelSet($Video, $Audio, $USB)
    {
        $this->WithLock(function () use ($Video, $Audio, $USB) {
            $this->SendCliCommand("channel -v " . $Video);
            IPS_Sleep(100);
            $this->SendCliCommand("channel -a " . $Audio);
            IPS_Sleep(100);
            $this->SendCliCommand("channel -u " . $USB);
        });
    }

    private function ApplySingleChannel($Service, $Channel)
    {
        $this->WithLock(function () use ($Service, $Channel) {
            $this->SendCliCommand("channel -" . $Service . " " . $Channel);
        });
        $this->UpdateLastAppliedStateFromProperties();
    }

    private function UpdateLastAppliedStateFromProperties()
    {
        $v = (int)$this->ReadPropertyInteger("VideoChannel");
        $a = (int)$this->ReadPropertyInteger("AudioChannel");
        $u = (int)$this->ReadPropertyInteger("USBChannel");

        if ($v > 0 && $a > 0 && $u > 0) {
            $host = (string)$this->ReadPropertyString("Host");
            $port = (int)$this->ReadPropertyInteger("Port");
            $this->WriteAttributeString("LastAppliedState", $this->BuildApplyState($host, $port, $v, $a, $u));
        }
    }

    private function BuildApplyState($Host, $Port, $Video, $Audio, $USB)
    {
        return $Host . "|" . $Port . "|" . $Video . "|" . $Audio . "|" . $USB;
    }

    private function IsRelevantResponse($buffer)
    {
        // Shell-Prompts herausfiltern
        if (preg_match('#^(/[a-zA-Z0-9/_-]+)\s*[#$>]\s*$#', $buffer)) {
            return false;
        }

        // Nur Prompt-Zeichen (#, $, >) herausfiltern
        if (preg_match('#^[#$>]\s*$#', $buffer)) {
            return false;
        }

        // Leere Zeilen ignorieren
        if ($buffer === "") {
            return false;
        }

        return true;
    }

    private function ReconnectParent()
    {
        $inst = IPS_GetInstance($this->InstanceID);
        $parentID = isset($inst["ConnectionID"]) ? (int)$inst["ConnectionID"] : 0;

        if ($parentID > 0 && IPS_InstanceExists($parentID)) {
            $this->CallSilenced(function () use ($parentID) {
                IPS_SetProperty($parentID, "Open", true);
                IPS_ApplyChanges($parentID);
            });
        }
    }

    private function DisconnectParent()
    {
        $inst = IPS_GetInstance($this->InstanceID);
        $parentID = isset($inst["ConnectionID"]) ? (int)$inst["ConnectionID"] : 0;

        if ($parentID > 0 && IPS_InstanceExists($parentID)) {
            $this->CallSilenced(function () use ($parentID) {
                IPS_SetProperty($parentID, "Open", false);
                IPS_ApplyChanges($parentID);
            });
        }
    }

    private function WithLock($Callable)
    {
        $key = "JAPMC_ENC_" . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, 5000)) throw new Exception("Device busy (semaphore timeout)");
        try {
            call_user_func($Callable);
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }

    private function TestTcp($Host, $Port, $ConnectTimeoutMs)
    {
        $errno = 0; $errstr = "";
        $timeoutSec = max(0.05, ((int)$ConnectTimeoutMs) / 1000.0);

        $fp = @fsockopen($Host, $Port, $errno, $errstr, $timeoutSec);
        if (is_resource($fp)) { fclose($fp); return true; }
        return false;
    }

    private function CallSilenced($Callable)
    {
        $old = set_error_handler(function () { return true; });
        try {
            call_user_func($Callable);
        } finally {
            if ($old !== null) {
                set_error_handler($old);
            } else {
                restore_error_handler();
            }
        }
    }
}
