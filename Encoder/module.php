<?php

class JAPMaxColorEncoderFlexible extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Client Socket (I/O)
        $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");

        $this->RegisterPropertyString("Host", "172.27.92.10");
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

        // Parent (Client Socket) konfigurieren
        $inst = IPS_GetInstance($this->InstanceID);
$parentID = isset($inst["ConnectionID"]) ? (int)$inst["ConnectionID"] : 0;

if ($parentID > 0 && IPS_InstanceExists($parentID)) {
    IPS_SetProperty($parentID, "Host", $this->ReadPropertyString("Host"));
    IPS_SetProperty($parentID, "Port", $this->ReadPropertyInteger("Port"));
    IPS_ApplyChanges($parentID);
}


        // Optional Auto-Assign
        if ($this->ReadPropertyBoolean("AutoAssignFromSchema")) {
            $this->AutoAssignIfNeeded();
        }

        $this->SetStatus(102);
    }

    public function ReceiveData($JSONString)
    {
        // SIMPLE: Buffer ist ein String (nicht base64)
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data["Buffer"])) {
            return;
        }

        $buffer = trim((string)$data["Buffer"]);
        if ($buffer !== "") {
            SetValueString($this->GetIDForIdent("LastResponse"), $buffer);
            $this->SendDebug("JAPMC ENC RX", $buffer, 0);
        }
    }

    public function ReadWebName()
    {
        $resp = $this->SendCliCommandAndBestEffortRead("astparam g webname");
        $name = $this->ParseWebName($resp);

        if ($name !== "") {
            IPS_SetProperty($this->InstanceID, "SourceName", $name);
            IPS_ApplyChanges($this->InstanceID);
        } else {
            IPS_LogMessage("JAPMC", "Encoder " . $this->ReadPropertyString("Host") . ": Could not parse webname from response.");
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

    // ---------------- Internals ----------------

    private function AutoAssignIfNeeded()
    {
        $regID = (int)$this->ReadPropertyInteger("RegistryInstanceID");
        if ($regID <= 0 || !IPS_InstanceExists($regID)) {
            return;
        }

        $v = (int)$this->ReadPropertyInteger("VideoChannel");
        $a = (int)$this->ReadPropertyInteger("AudioChannel");
        $u = (int)$this->ReadPropertyInteger("USBChannel");

        // Nur setzen, wenn noch nicht konfiguriert
        if ($v != 0 || $a != 0 || $u != 0) {
            return;
        }

        $n = @JAPMC_RegistryGetNextFreeIndex($regID);
        if (!is_int($n) || $n < 0) {
            IPS_LogMessage("JAPMC", "Registry has no free index (or not available).");
            return;
        }

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
        // SIMPLE: DataID + Buffer als Klartext
        $suffix  = $this->ReadPropertyBoolean("UseCRLF") ? "\r\n" : "\n";
        $payload = $Command . $suffix;

        $data = array(
            "DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}",
            "Buffer" => $payload
        );

        $this->SendDataToParent(json_encode($data));
        $this->SendDebug("JAPMC ENC TX", $Command, 0);
    }

    private function SendCliCommandAndBestEffortRead($Command)
    {
        // In IP-Symcon ist Read über Client Socket nicht synchron garantiert:
        // Wir senden und warten kurz; ReceiveData schreibt in LastResponse.
        $this->WithLock(function () use ($Command) {
            $this->SendCliCommand($Command);
        });

        IPS_Sleep(250);
        return (string)GetValueString($this->GetIDForIdent("LastResponse"));
    }

    private function ParseWebName($Response)
    {
        $lines = preg_split("/\r\n|\n|\r/", (string)$Response);
        foreach ($lines as $l) {
            $t = trim($l);
            if ($t === "") {
                continue;
            }

            if (stripos($t, "webname") !== false) {
                $parts = preg_split("/=|:/", $t);
                if (is_array($parts) && count($parts) >= 2) {
                    $candidate = trim($parts[count($parts) - 1]);
                    $candidate = trim($candidate, "\"'");
                    if ($candidate !== "") {
                        return $candidate;
                    }
                }
            } else {
                $candidate = trim($t, "\"'");
                if ($candidate !== "" && strlen($candidate) < 128) {
                    return $candidate;
                }
            }
        }
        return "";
    }

    private function WithLock($Callable)
    {
        $key = "JAPMC_ENC_" . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, 5000)) {
            throw new Exception("Device busy (semaphore timeout)");
        }
        try {
            call_user_func($Callable);
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }
}
