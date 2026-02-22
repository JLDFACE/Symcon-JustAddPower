<?php

class JAPMaxColorDecoderFlexible extends IPSModule
{
    private $TX = "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}";

    public function Create()
    {
        parent::Create();

        $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");

        $this->RegisterPropertyString("Host", "192.168.10.100");
        $this->RegisterPropertyInteger("Port", 23);
        $this->RegisterPropertyBoolean("UseCRLF", true);

        $this->RegisterPropertyInteger("RegistryInstanceID", 0);
        $this->RegisterPropertyInteger("CommandDelayMs", 100);
        $this->RegisterPropertyBoolean("EnableUSBRouting", false);

        $this->RegisterPropertyBoolean("DefaultAudioFollowsVideo", true);
        $this->RegisterPropertyBoolean("DefaultUSBFollowsVideo", true);

        $this->RegisterPropertyString("Presets", "[]");

        $this->RegisterVariableBoolean("Online", "Online", "~Alert.Reversed", 1);
        IPS_SetIcon($this->GetIDForIdent("Online"), "Network");

        $this->RegisterVariableInteger("VideoSource", "Video Quelle", "", 10);
        $this->EnableAction("VideoSource");

        $this->RegisterVariableInteger("AudioSource", "Audio Quelle", "", 11);
        $this->EnableAction("AudioSource");

        $this->RegisterVariableInteger("USBSource", "USB Quelle", "", 12);
        $this->EnableAction("USBSource");

        $this->RegisterVariableBoolean("AudioFollowsVideo", "Audio folgt Video", "", 20);
        $this->EnableAction("AudioFollowsVideo");

        $this->RegisterVariableBoolean("USBFollowsVideo", "USB folgt Video", "", 21);
        $this->EnableAction("USBFollowsVideo");

        $this->RegisterVariableInteger("Preset", "Preset", "", 30);
        $this->EnableAction("Preset");

        $this->RegisterVariableString("LastResponse", "Last Response", "", 90);

        $this->RegisterAttributeString("ProfileHash", "");
        $this->RegisterAttributeString("SelectedVideoName", "");
        $this->RegisterAttributeString("SelectedAudioName", "");
        $this->RegisterAttributeString("SelectedUSBName", "");
        $this->RegisterAttributeString("Initialized", "0");
        $this->RegisterAttributeBoolean("WasOnline", false);

        $this->RegisterTimer("RefreshTimer", 60000, 'JAPMC_RefreshSources($_IPS["TARGET"]);');
        $this->RegisterTimer("OnlineCheckTimer", 30000, 'JAPMC_CheckOnlineStatus($_IPS["TARGET"]);');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        if (!is_array($form)) {
            $form = array("elements" => array(), "actions" => array());
        }

        $usbEnabled = $this->IsUSBRoutingEnabled();

        if (isset($form["elements"]) && is_array($form["elements"])) {
            foreach ($form["elements"] as $k => $e) {
                if (!isset($e["name"])) {
                    continue;
                }

                if ($e["name"] === "DefaultUSBFollowsVideo") {
                    $form["elements"][$k]["visible"] = $usbEnabled;
                }

                if ($e["name"] === "Presets" && isset($e["columns"]) && is_array($e["columns"])) {
                    if ($usbEnabled) {
                        continue;
                    }

                    $cols = array();
                    foreach ($e["columns"] as $col) {
                        $colName = isset($col["name"]) ? (string)$col["name"] : "";
                        if ($colName === "USBSource") {
                            continue;
                        }
                        $cols[] = $col;
                    }
                    $form["elements"][$k]["columns"] = $cols;
                    $form["elements"][$k]["caption"] = "Presets (SourceNames; Audio leer => folgt Video)";
                }
            }
        }

        return json_encode($form);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $host = (string)$this->ReadPropertyString("Host");
        $port = (int)$this->ReadPropertyInteger("Port");

        $inst = IPS_GetInstance($this->InstanceID);
        $parentID = isset($inst["ConnectionID"]) ? (int)$inst["ConnectionID"] : 0;

        if ($parentID > 0 && IPS_InstanceExists($parentID)) {
            IPS_SetProperty($parentID, "Host", $host);
            IPS_SetProperty($parentID, "Port", $port);

            $canConnect = $this->TestTcp($host, $port, 250);
            $this->SendDebug("JAPMC DEC", "AutoOpen=" . ($canConnect ? "true" : "false") . " for " . $host . ":" . $port, 0);

            $this->CallSilenced(function () use ($parentID, $canConnect) {
                IPS_SetProperty($parentID, "Open", $canConnect);
            });

            $this->CallSilenced(function () use ($parentID) {
                IPS_ApplyChanges($parentID);
            });
        }

        $usbEnabled = $this->IsUSBRoutingEnabled();

        if ($this->ReadAttributeString("Initialized") !== "1") {
            SetValueBoolean($this->GetIDForIdent("AudioFollowsVideo"), (bool)$this->ReadPropertyBoolean("DefaultAudioFollowsVideo"));
            SetValueBoolean($this->GetIDForIdent("USBFollowsVideo"), $usbEnabled ? (bool)$this->ReadPropertyBoolean("DefaultUSBFollowsVideo") : false);
            $this->WriteAttributeString("Initialized", "1");
        }

        if (!$usbEnabled && GetValueBoolean($this->GetIDForIdent("USBFollowsVideo"))) {
            SetValueBoolean($this->GetIDForIdent("USBFollowsVideo"), false);
        }

        $this->SetUSBVariableVisibility($usbEnabled);
        $this->RefreshSources();
        $this->CheckOnlineStatus();

        $this->SetStatus(102);
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data["Buffer"])) return;

        $buffer = trim((string)$data["Buffer"]);
        if ($buffer !== "") {
            $this->SendDebug("JAPMC DEC RX", $buffer, 0);

            // Shell-Prompts und leere Antworten ignorieren
            if ($this->IsRelevantResponse($buffer)) {
                SetValueString($this->GetIDForIdent("LastResponse"), $buffer);
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == "VideoSource") {
            $regID = (int)$this->ReadPropertyInteger("RegistryInstanceID");
            if ($regID <= 0 || !IPS_InstanceExists($regID)) {
                echo "Keine Registry-Instanz konfiguriert.\n";
                echo "Bitte wählen Sie eine gültige Registry-Instanz in der Konfiguration.";
                return;
            }

            $idx = (int)$Value;
            $name = $this->GetSourceNameByIndex($idx);

            if ($name === "") {
                echo "Ungültige Video-Quelle ausgewählt.\n";
                echo "Index: " . $idx . "\n\n";
                echo "Bitte aktualisieren Sie die Quellenliste in der Registry oder warten Sie auf die automatische Aktualisierung.";
                return;
            }

            $this->WithLock(function () use ($idx, $name) {
                $this->SwitchServiceBySourceName("v", $name);
                $this->WriteAttributeString("SelectedVideoName", $name);

                if (GetValueBoolean($this->GetIDForIdent("AudioFollowsVideo"))) {
                    $this->SwitchServiceBySourceName("a", $name);
                    $this->WriteAttributeString("SelectedAudioName", $name);
                    SetValueInteger($this->GetIDForIdent("AudioSource"), $idx);
                }

                if ($this->IsUSBRoutingEnabled() && GetValueBoolean($this->GetIDForIdent("USBFollowsVideo"))) {
                    $this->SwitchServiceBySourceName("u", $name);
                    $this->WriteAttributeString("SelectedUSBName", $name);
                    SetValueInteger($this->GetIDForIdent("USBSource"), $idx);
                }
            });

            SetValueInteger($this->GetIDForIdent("VideoSource"), $idx);
            return;
        }

        if ($Ident == "AudioSource") {
            $regID = (int)$this->ReadPropertyInteger("RegistryInstanceID");
            if ($regID <= 0 || !IPS_InstanceExists($regID)) {
                echo "Keine Registry-Instanz konfiguriert.\n";
                echo "Bitte wählen Sie eine gültige Registry-Instanz in der Konfiguration.";
                return;
            }

            $idx = (int)$Value;
            $name = $this->GetSourceNameByIndex($idx);

            if ($name === "") {
                echo "Ungültige Audio-Quelle ausgewählt.\n";
                echo "Index: " . $idx . "\n\n";
                echo "Bitte aktualisieren Sie die Quellenliste in der Registry oder warten Sie auf die automatische Aktualisierung.";
                return;
            }

            $this->WithLock(function () use ($name) {
                $this->SwitchServiceBySourceName("a", $name);
                $this->WriteAttributeString("SelectedAudioName", $name);
            });

            SetValueInteger($this->GetIDForIdent("AudioSource"), $idx);

            if (GetValueBoolean($this->GetIDForIdent("AudioFollowsVideo"))) {
                SetValueBoolean($this->GetIDForIdent("AudioFollowsVideo"), false);
            }
            return;
        }

        if ($Ident == "USBSource") {
            if (!$this->IsUSBRoutingEnabled()) {
                echo "USB-Routing ist deaktiviert.\n";
                echo "Aktivieren Sie in der Konfiguration 'USB-Felder anzeigen'.";
                return;
            }

            $regID = (int)$this->ReadPropertyInteger("RegistryInstanceID");
            if ($regID <= 0 || !IPS_InstanceExists($regID)) {
                echo "Keine Registry-Instanz konfiguriert.\n";
                echo "Bitte wählen Sie eine gültige Registry-Instanz in der Konfiguration.";
                return;
            }

            $idx = (int)$Value;
            $name = $this->GetSourceNameByIndex($idx);

            if ($name === "") {
                echo "Ungültige USB-Quelle ausgewählt.\n";
                echo "Index: " . $idx . "\n\n";
                echo "Bitte aktualisieren Sie die Quellenliste in der Registry oder warten Sie auf die automatische Aktualisierung.";
                return;
            }

            $this->WithLock(function () use ($name) {
                $this->SwitchServiceBySourceName("u", $name);
                $this->WriteAttributeString("SelectedUSBName", $name);
            });

            SetValueInteger($this->GetIDForIdent("USBSource"), $idx);

            if (GetValueBoolean($this->GetIDForIdent("USBFollowsVideo"))) {
                SetValueBoolean($this->GetIDForIdent("USBFollowsVideo"), false);
            }
            return;
        }

        if ($Ident == "AudioFollowsVideo") {
            $flag = (bool)$Value;
            SetValueBoolean($this->GetIDForIdent("AudioFollowsVideo"), $flag);
            return;
        }

        if ($Ident == "USBFollowsVideo") {
            if (!$this->IsUSBRoutingEnabled()) {
                SetValueBoolean($this->GetIDForIdent("USBFollowsVideo"), false);
                return;
            }

            $flag = (bool)$Value;
            SetValueBoolean($this->GetIDForIdent("USBFollowsVideo"), $flag);
            return;
        }

        if ($Ident == "Preset") {
            SetValueInteger($this->GetIDForIdent("Preset"), (int)$Value);
            return;
        }

        throw new Exception("Invalid Ident");
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
            $this->SendDebug("JAPMC DEC Reconnect", "Device came online, reconnecting parent", 0);
            $this->ReconnectParent();
        }

        // War online, ist jetzt offline -> Parent-Verbindung schließen
        if ($wasOnline && !$isOnline) {
            $this->SendDebug("JAPMC DEC Disconnect", "Device went offline, closing parent", 0);
            $this->DisconnectParent();
        }

        $this->WriteAttributeBoolean("WasOnline", $isOnline);
        $this->SendDebug("JAPMC DEC Online", $isOnline ? "true" : "false", 0);
    }

    public function RefreshSources()
    {
        $sources = $this->GetSourcesFromRegistry();
        $hash = md5(json_encode($sources));
        if ($hash === $this->ReadAttributeString("ProfileHash")) return;

        $this->SyncSourceProfile($sources);
        $this->WriteAttributeString("ProfileHash", $hash);
    }

    private function SwitchServiceBySourceName($Service, $SourceName)
    {
        $src = $this->ResolveSource($SourceName);
        if (!is_array($src)) {
            echo "Quelle nicht in Registry gefunden: " . $SourceName . "\n";
            echo "Bitte überprüfen Sie die Registry-Konfiguration.";
            return;
        }

        $ch = 0;
        if ($Service == "v") $ch = isset($src["Video"]) ? (int)$src["Video"] : 0;
        if ($Service == "a") $ch = isset($src["Audio"]) ? (int)$src["Audio"] : 0;
        if ($Service == "u") $ch = isset($src["USB"]) ? (int)$src["USB"] : 0;

        if ($ch <= 0) {
            $serviceName = ($Service == "v" ? "Video" : ($Service == "a" ? "Audio" : "USB"));
            echo "Ungültiger " . $serviceName . "-Kanal für Quelle '" . $SourceName . "': " . $ch . "\n";
            echo "Bitte konfigurieren Sie einen gültigen Kanal in der Registry.";
            return;
        }

        $this->SendCliCommand("channel -" . $Service . " " . $ch);
        $this->Delay();
    }

    private function SendCliCommand($Command)
    {
        $suffix  = $this->ReadPropertyBoolean("UseCRLF") ? "\r\n" : "\n";
        $payload = $Command . $suffix;

        $data = array("DataID" => $this->TX, "Buffer" => $payload);
        $this->SendDataToParent(json_encode($data));
        $this->SendDebug("JAPMC DEC TX", $Command, 0);
    }

    private function Delay()
    {
        $ms = (int)$this->ReadPropertyInteger("CommandDelayMs");
        if ($ms > 0) IPS_Sleep($ms);
    }

    private function GetSourcesFromRegistry()
    {
        $regID = (int)$this->ReadPropertyInteger("RegistryInstanceID");
        if ($regID <= 0 || !IPS_InstanceExists($regID)) return array();

        $json = @JAPMC_RegistryGetSources($regID);
        $arr = json_decode((string)$json, true);
        if (!is_array($arr)) return array();
        return $arr;
    }

    private function ResolveSource($Name)
    {
        $regID = (int)$this->ReadPropertyInteger("RegistryInstanceID");
        if ($regID <= 0 || !IPS_InstanceExists($regID)) return null;

        $json = @JAPMC_RegistryResolveSource($regID, (string)$Name);
        $obj = json_decode((string)$json, true);
        if (!is_array($obj)) return null;
        return $obj;
    }

    private function SyncSourceProfile($Sources)
    {
        $profile = "JAPMC.Source." . $this->InstanceID;

        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        } else {
            $p = IPS_GetVariableProfile($profile);
            if (isset($p["Associations"])) {
                foreach ($p["Associations"] as $a) {
                    IPS_SetVariableProfileAssociation($profile, $a["Value"], "", "", -1);
                }
            }
        }

        for ($i = 0; $i < count($Sources); $i++) {
            $name = isset($Sources[$i]["Name"]) ? (string)$Sources[$i]["Name"] : ("Source " . $i);
            IPS_SetVariableProfileAssociation($profile, $i, $name, "", -1);
        }

        IPS_SetVariableCustomProfile($this->GetIDForIdent("VideoSource"), $profile);
        IPS_SetVariableCustomProfile($this->GetIDForIdent("AudioSource"), $profile);
        IPS_SetVariableCustomProfile($this->GetIDForIdent("USBSource"), $profile);
    }

    private function GetSourceNameByIndex($Index)
    {
        $sources = $this->GetSourcesFromRegistry();
        if (!isset($sources[$Index])) return "";
        return isset($sources[$Index]["Name"]) ? (string)$sources[$Index]["Name"] : "";
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
        $key = "JAPMC_DEC_" . $this->InstanceID;
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

    private function IsUSBRoutingEnabled()
    {
        return (bool)$this->ReadPropertyBoolean("EnableUSBRouting");
    }

    private function SetUSBVariableVisibility($Enabled)
    {
        $usbSourceID = @$this->GetIDForIdent("USBSource");
        if ((int)$usbSourceID > 0) {
            IPS_SetHidden($usbSourceID, !$Enabled);
        }

        $usbFollowID = @$this->GetIDForIdent("USBFollowsVideo");
        if ((int)$usbFollowID > 0) {
            IPS_SetHidden($usbFollowID, !$Enabled);
        }
    }
}
