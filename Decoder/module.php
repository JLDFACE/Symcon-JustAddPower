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

        $this->RegisterPropertyBoolean("DefaultAudioFollowsVideo", true);
        $this->RegisterPropertyBoolean("DefaultUSBFollowsVideo", true);

        $this->RegisterPropertyString("Presets", "[]");

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
            $this->SendDebug("JAPMC DEC", "AutoOpen=" . ($canConnect ? "true" : "false") . " for " . $host . ":" . $port, 0);

            $this->CallSilenced(function () use ($parentID, $canConnect) {
                IPS_SetProperty($parentID, "Open", $canConnect);
            });

            $this->CallSilenced(function () use ($parentID) {
                IPS_ApplyChanges($parentID);
            });
        }

        if ($this->ReadAttributeString("Initialized") !== "1") {
            SetValueBoolean($this->GetIDForIdent("AudioFollowsVideo"), (bool)$this->ReadPropertyBoolean("DefaultAudioFollowsVideo"));
            SetValueBoolean($this->GetIDForIdent("USBFollowsVideo"), (bool)$this->ReadPropertyBoolean("DefaultUSBFollowsVideo"));
            $this->WriteAttributeString("Initialized", "1");
        }

        $this->RefreshSources();
        $this->SetStatus(102);
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data["Buffer"])) return;

        $buffer = trim((string)$data["Buffer"]);
        if ($buffer !== "") {
            SetValueString($this->GetIDForIdent("LastResponse"), $buffer);
            $this->SendDebug("JAPMC DEC RX", $buffer, 0);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == "VideoSource") {
            $idx = (int)$Value;

            $this->WithLock(function () use ($idx) {
                $name = $this->GetSourceNameByIndex($idx);
                if ($name === "") throw new Exception("Invalid VideoSource selection");

                $this->SwitchServiceBySourceName("v", $name);
                $this->WriteAttributeString("SelectedVideoName", $name);

                if (GetValueBoolean($this->GetIDForIdent("AudioFollowsVideo"))) {
                    $this->SwitchServiceBySourceName("a", $name);
                    $this->WriteAttributeString("SelectedAudioName", $name);
                    SetValueInteger($this->GetIDForIdent("AudioSource"), $idx);
                }

                if (GetValueBoolean($this->GetIDForIdent("USBFollowsVideo"))) {
                    $this->SwitchServiceBySourceName("u", $name);
                    $this->WriteAttributeString("SelectedUSBName", $name);
                    SetValueInteger($this->GetIDForIdent("USBSource"), $idx);
                }
            });

            SetValueInteger($this->GetIDForIdent("VideoSource"), $idx);
            return;
        }

        if ($Ident == "AudioSource") {
            $idx = (int)$Value;

            $this->WithLock(function () use ($idx) {
                $name = $this->GetSourceNameByIndex($idx);
                if ($name === "") throw new Exception("Invalid AudioSource selection");

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
            $idx = (int)$Value;

            $this->WithLock(function () use ($idx) {
                $name = $this->GetSourceNameByIndex($idx);
                if ($name === "") throw new Exception("Invalid USBSource selection");

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
        if (!is_array($src)) throw new Exception("Source not found in registry: " . $SourceName);

        $ch = 0;
        if ($Service == "v") $ch = (int)$src["Video"];
        if ($Service == "a") $ch = (int)$src["Audio"];
        if ($Service == "u") $ch = (int)$src["USB"];

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
}
