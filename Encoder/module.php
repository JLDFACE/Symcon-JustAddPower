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

        $this->RegisterVariableString("LastResponse", "Last Response", "", 90);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $host = (string)$this->ReadPropertyString("Host");
        $port = (int)$this->ReadPropertyInteger("Port");

        // Parent (Client Socket) konfigurieren
        $inst = IPS_GetInstance($this->InstanceID);
        $parentID = isset($inst["ConnectionID"]) ? (int)$inst["ConnectionID"] : 0;

        if ($parentID > 0 && IPS_InstanceExists($parentID)) {
            IPS_SetProperty($parentID, "Host", $host);
            IPS_SetProperty($parentID, "Port", $port);

            // Auto-Open nur wenn erreichbar
            if ($this->HasParentOpenProperty($parentID)) {
                $canConnect = $this->TestTcp($host, $port, 250);
                IPS_SetProperty($parentID, "Open", $canConnect);
                $this->SendDebug("JAPMC ENC", "AutoOpen=" . ($canConnect ? "true" : "false") . " for " . $host . ":" . $port, 0);
            }

            // Warnings beim ApplyChanges des Parents abfangen
            $this->CallSilenced(function () use ($parentID) {
                IPS_ApplyChanges($parentID);
            });
        }

        if ($this->ReadPropertyBoolean("AutoAssignFromSchema")) {
            $this->AutoAssignIfNeeded();
        }

        $this->SetStatus(102);
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data["Buffer"])) return;

        $buffer = trim((string)$data["Buffer"]);
        if ($buffer !== "") {
            SetValueString($this->GetIDForIdent("LastResponse"), $buffer);
            $this->SendDebug("JAPMC ENC RX", $buffer, 0);
        }
    }

    public function ApplyChannels()
    {
        $v = (int)$this->ReadPropertyInteger("VideoChannel");
        $a = (int)$this->ReadPropertyInteger("AudioChannel");
        $u = (int)$this->ReadPropertyInteger("USBChannel");

        $this->WithLock(function () use ($v, $a, $u) {
            $this->SendCliCommand("channel -v " . $v);
            IPS_Sleep(100);
            $this->SendCliCommand("channel -a " . $a);
            IPS_Sleep(100);
            $this->SendCliCommand("channel -u " . $u);
        });
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

    private function SendCliCommand($Command)
    {
        $suffix  = $this->ReadPropertyBoolean("UseCRLF") ? "\r\n" : "\n";
        $payload = $Command . $suffix;

        $data = array("DataID" => $this->TX, "Buffer" => $payload);
        $this->SendDataToParent(json_encode($data));
        $this->SendDebug("JAPMC ENC TX", $Command, 0);
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

    private function HasParentOpenProperty($InstanceID)
    {
        $props = IPS_GetPropertyList($InstanceID);
        return is_array($props) && in_array("Open", $props);
    }
}
