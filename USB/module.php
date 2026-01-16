<?php

class JAPMaxColorUSBDevice extends IPSModule
{
    private $TX = "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}";

    public function Create()
    {
        parent::Create();

        $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");

        $this->RegisterPropertyString("Host", "192.168.10.200");
        $this->RegisterPropertyInteger("Port", 23);
        $this->RegisterPropertyBoolean("UseCRLF", true);

        $this->RegisterPropertyString("USBMode", "CLIENT");

        // Sender-Eigenschaften
        $this->RegisterPropertyInteger("RegistryInstanceID", 0);
        $this->RegisterPropertyString("SourceName", "");
        $this->RegisterPropertyInteger("USBChannel", 0);
        $this->RegisterPropertyBoolean("AutoAssignFromSchema", true);

        // Receiver-Eigenschaften
        $this->RegisterPropertyInteger("RegistryInstanceIDReceiver", 0);
        $this->RegisterPropertyInteger("CommandDelayMs", 100);

        $this->RegisterVariableBoolean("Online", "Online", "~Alert.Reversed", 1);
        IPS_SetIcon($this->GetIDForIdent("Online"), "Network");

        $this->RegisterVariableInteger("USBSource", "USB Quelle", "", 10);
        $this->EnableAction("USBSource");

        $this->RegisterVariableString("LastResponse", "Last Response", "", 90);

        $this->RegisterAttributeBoolean("WasOnline", false);
        $this->RegisterAttributeString("ProfileHash", "");

        $this->RegisterTimer("OnlineCheckTimer", 30000, 'JAPMC_CheckOnlineStatus($_IPS["TARGET"]);');
        $this->RegisterTimer("RefreshTimer", 60000, 'JAPMC_RefreshSources($_IPS["TARGET"]);');
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
            $this->SendDebug("JAPMC USB", "AutoOpen=" . ($canConnect ? "true" : "false") . " for " . $host . ":" . $port, 0);

            $this->CallSilenced(function () use ($parentID, $canConnect) {
                IPS_SetProperty($parentID, "Open", $canConnect);
            });

            $this->CallSilenced(function () use ($parentID) {
                IPS_ApplyChanges($parentID);
            });
        }

        $mode = (string)$this->ReadPropertyString("USBMode");

        if ($mode === "HOST") {
            if ($this->ReadPropertyBoolean("AutoAssignFromSchema")) {
                $this->AutoAssignIfNeeded();
            }
        }

        if ($mode === "CLIENT") {
            $this->RefreshSources();
        }

        $this->CheckOnlineStatus();

        $this->SetStatus(102);
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data["Buffer"])) return;

        $buffer = trim((string)$data["Buffer"]);
        if ($buffer !== "") {
            $this->SendDebug("JAPMC USB RX", $buffer, 0);

            if ($this->IsRelevantResponse($buffer)) {
                SetValueString($this->GetIDForIdent("LastResponse"), $buffer);
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == "USBSource") {
            $mode = (string)$this->ReadPropertyString("USBMode");
            if ($mode !== "CLIENT") {
                echo "USB-Quellenwahl ist nur im USB Client-Modus verfügbar.\n";
                echo "Aktueller Modus: USB Host\n\n";
                echo "Bitte ändern Sie den USB Modus auf 'USB Client (sendet USB an Enc/Dec)' um Quellen zu wählen.";
                return;
            }

            $regID = (int)$this->ReadPropertyInteger("RegistryInstanceIDReceiver");
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
                $this->SwitchUSBBySourceName($name);
            });

            SetValueInteger($this->GetIDForIdent("USBSource"), $idx);
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

        if (!$wasOnline && $isOnline) {
            $this->SendDebug("JAPMC USB Reconnect", "Device came online, reconnecting parent", 0);
            $this->ReconnectParent();
        }

        if ($wasOnline && !$isOnline) {
            $this->SendDebug("JAPMC USB Disconnect", "Device went offline, closing parent", 0);
            $this->DisconnectParent();
        }

        $this->WriteAttributeBoolean("WasOnline", $isOnline);
        $this->SendDebug("JAPMC USB Online", $isOnline ? "true" : "false", 0);
    }

    public function ApplyUSBChannel()
    {
        $mode = (string)$this->ReadPropertyString("USBMode");
        if ($mode !== "HOST") {
            echo "Diese Funktion ist nur im USB Host-Modus verfügbar.\n";
            echo "Aktueller Modus: USB Client\n\n";
            echo "Bitte ändern Sie den USB Modus auf 'USB Host (empfängt USB am MC-2)' um USB-Kanäle zu konfigurieren.";
            return;
        }

        $u = (int)$this->ReadPropertyInteger("USBChannel");

        if ($u <= 0) {
            echo "Ungültiger USB-Kanal: " . $u . "\n";
            echo "Bitte geben Sie einen gültigen USB-Kanal (1-9999) an.";
            return;
        }

        $this->WithLock(function () use ($u) {
            $this->SendCliCommand("channel -u " . $u);
        });

        echo "USB-Kanal " . $u . " wurde erfolgreich angewendet.";
    }

    public function RefreshSources()
    {
        $mode = (string)$this->ReadPropertyString("USBMode");
        if ($mode !== "CLIENT") return;

        $sources = $this->GetSourcesFromRegistry();
        $hash = md5(json_encode($sources));
        if ($hash === $this->ReadAttributeString("ProfileHash")) return;

        $this->SyncSourceProfile($sources);
        $this->WriteAttributeString("ProfileHash", $hash);
    }

    private function SwitchUSBBySourceName($SourceName)
    {
        $src = $this->ResolveSource($SourceName);
        if (!is_array($src)) {
            echo "Quelle nicht in Registry gefunden: " . $SourceName . "\n";
            echo "Bitte überprüfen Sie die Registry-Konfiguration.";
            return;
        }

        $ch = isset($src["USB"]) ? (int)$src["USB"] : 0;

        if ($ch <= 0) {
            echo "Ungültiger USB-Kanal für Quelle '" . $SourceName . "': " . $ch . "\n";
            echo "Bitte konfigurieren Sie einen gültigen USB-Kanal in der Registry.";
            return;
        }

        $this->SendCliCommand("channel -u " . $ch);
        $this->Delay();
    }

    private function GetSourcesFromRegistry()
    {
        $regID = (int)$this->ReadPropertyInteger("RegistryInstanceIDReceiver");
        if ($regID <= 0 || !IPS_InstanceExists($regID)) return array();

        $json = @JAPMC_RegistryGetSources($regID);
        $arr = json_decode((string)$json, true);
        if (!is_array($arr)) return array();
        return $arr;
    }

    private function ResolveSource($Name)
    {
        $regID = (int)$this->ReadPropertyInteger("RegistryInstanceIDReceiver");
        if ($regID <= 0 || !IPS_InstanceExists($regID)) return null;

        $json = @JAPMC_RegistryResolveSource($regID, (string)$Name);
        $obj = json_decode((string)$json, true);
        if (!is_array($obj)) return null;
        return $obj;
    }

    private function SyncSourceProfile($Sources)
    {
        $profile = "JAPMC.USBSource." . $this->InstanceID;

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

        IPS_SetVariableCustomProfile($this->GetIDForIdent("USBSource"), $profile);
    }

    private function GetSourceNameByIndex($Index)
    {
        $sources = $this->GetSourcesFromRegistry();
        if (!isset($sources[$Index])) return "";
        return isset($sources[$Index]["Name"]) ? (string)$sources[$Index]["Name"] : "";
    }

    private function AutoAssignIfNeeded()
    {
        $regID = (int)$this->ReadPropertyInteger("RegistryInstanceID");
        if ($regID <= 0 || !IPS_InstanceExists($regID)) return;

        $u = (int)$this->ReadPropertyInteger("USBChannel");
        if ($u != 0) return;

        $n = (int)@JAPMC_RegistryGetNextFreeIndex($regID);
        if ($n < 0) return;

        $usbBase = (int)IPS_GetProperty($regID, "USBBase");

        IPS_SetProperty($this->InstanceID, "USBChannel", $usbBase + $n);
        IPS_ApplyChanges($this->InstanceID);
    }

    private function SendCliCommand($Command)
    {
        $suffix  = $this->ReadPropertyBoolean("UseCRLF") ? "\r\n" : "\n";
        $payload = $Command . $suffix;

        $data = array("DataID" => $this->TX, "Buffer" => $payload);
        $this->SendDataToParent(json_encode($data));
        $this->SendDebug("JAPMC USB TX", $Command, 0);
    }

    private function Delay()
    {
        $ms = (int)$this->ReadPropertyInteger("CommandDelayMs");
        if ($ms > 0) IPS_Sleep($ms);
    }

    private function IsRelevantResponse($buffer)
    {
        if (preg_match('#^(/[a-zA-Z0-9/_-]+)\s*[#$>]\s*$#', $buffer)) {
            return false;
        }

        if (preg_match('#^[#$>]\s*$#', $buffer)) {
            return false;
        }

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
        $key = "JAPMC_USB_" . $this->InstanceID;
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
