<?php

class JAPMaxColorDecoderFlexible extends IPSModule
{
    private $TX = "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}";

    public function Create()
    {
        parent::Create();

        // Client Socket
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

        $inst = IPS_GetInstance($this->InstanceID);
        $parentID = isset($inst["ConnectionID"]) ? (int)$inst["ConnectionID"] : 0;

        // Parent konfigurieren + automatisch öffnen
        if ($parentID > 0 && IPS_InstanceExists($parentID)) {
            IPS_SetProperty($parentID, "Host", $this->ReadPropertyString("Host"));
            IPS_SetProperty($parentID, "Port", $this->ReadPropertyInteger("Port"));
            IPS_SetProperty($parentID, "Open", true);
            IPS_ApplyChanges($parentID);
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

            if ($flag) {
                $vidName = $this->ReadAttributeString("SelectedVideoName");
                if ($vidName !== "") {
                    $this->WithLock(function () use ($vidName) {
                        $this->SwitchServiceBySourceName("a", $vidName);
                    });

                    $idx = $this->FindSourceIndexByName($vidName);
                    if ($idx >= 0) SetValueInteger($this->GetIDForIdent("AudioSource"), $idx);

                    $this->WriteAttributeString("SelectedAudioName", $vidName);
                }
            }
            return;
        }

        if ($Ident == "USBFollowsVideo") {
            $flag = (bool)$Value;
            SetValueBoolean($this->GetIDForIdent("USBFollowsVideo"), $flag);

            if ($flag) {
                $vidName = $this->ReadAttributeString("SelectedVideoName");
                if ($vidName !== "") {
                    $this->WithLock(function () use ($vidName) {
                        $this->SwitchServiceBySourceName("u", $vidName);
                    });

                    $idx = $this->FindSourceIndexByName($vidName);
                    if ($idx >= 0) SetValueInteger($this->GetIDForIdent("USBSource"), $idx);

                    $this->WriteAttributeString("SelectedUSBName", $vidName);
                }
            }
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
        $presets = $this->GetPresets();

        $hash = md5(json_encode($sources) . "|" . json_encode($presets));
        if ($hash === $this->ReadAttributeString("ProfileHash")) return;

        $this->SyncSourceProfile($sources);
        $this->SyncPresetProfile($presets);
        $this->RestoreSelections($sources);

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

        if ($ch < 0 || $ch > 9999) throw new Exception("Channel out of range for " . $SourceName . ": " . $ch);

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
        if (!is_string($json) || $json === "") return array();

        $arr = json_decode($json, true);
        if (!is_array($arr)) return array();

        return $arr;
    }

    private function ResolveSource($Name)
    {
        $regID = (int)$this->ReadPropertyInteger("RegistryInstanceID");
        if ($regID <= 0 || !IPS_InstanceExists($regID)) return null;

        $json = @JAPMC_RegistryResolveSource($regID, (string)$Name);
        if (!is_string($json) || $json === "") return null;

        $obj = json_decode($json, true);
        if (!is_array($obj)) return null;

        return $obj;
    }

    private function GetPresets()
    {
        $raw = $this->ReadPropertyString("Presets");
        $arr = json_decode($raw, true);
        if (!is_array($arr)) return array();
        return array_values($arr);
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

    private function SyncPresetProfile($Presets)
    {
        $profile = "JAPMC.Preset." . $this->InstanceID;

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

        for ($i = 0; $i < count($Presets); $i++) {
            $name = isset($Presets[$i]["Name"]) ? (string)$Presets[$i]["Name"] : ("Preset " . $i);
            IPS_SetVariableProfileAssociation($profile, $i, $name, "", -1);
        }

        IPS_SetVariableCustomProfile($this->GetIDForIdent("Preset"), $profile);
    }

    private function RestoreSelections($Sources)
    {
        $vName = $this->ReadAttributeString("SelectedVideoName");
        $aName = $this->ReadAttributeString("SelectedAudioName");
        $uName = $this->ReadAttributeString("SelectedUSBName");

        if ($vName !== "") {
            $idx = $this->FindSourceIndexByName($vName);
            if ($idx >= 0) SetValueInteger($this->GetIDForIdent("VideoSource"), $idx);
        }
        if ($aName !== "") {
            $idx = $this->FindSourceIndexByName($aName);
            if ($idx >= 0) SetValueInteger($this->GetIDForIdent("AudioSource"), $idx);
        }
        if ($uName !== "") {
            $idx = $this->FindSourceIndexByName($uName);
            if ($idx >= 0) SetValueInteger($this->GetIDForIdent("USBSource"), $idx);
        }
    }

    private function GetSourceNameByIndex($Index)
    {
        $sources = $this->GetSourcesFromRegistry();
        if (!isset($sources[$Index])) return "";
        return isset($sources[$Index]["Name"]) ? (string)$sources[$Index]["Name"] : "";
    }

    private function FindSourceIndexByName($Name)
    {
        $sources = $this->GetSourcesFromRegistry();
        $key = mb_strtolower((string)$Name);

        for ($i = 0; $i < count($sources); $i++) {
            $n = isset($sources[$i]["Name"]) ? (string)$sources[$i]["Name"] : "";
            if (mb_strtolower($n) === $key) return $i;
        }
        return -1;
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
}
