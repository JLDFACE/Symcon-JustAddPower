<?php

class JAPMaxColorConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString("ScanFrom", "172.27.92.1");
        $this->RegisterPropertyString("ScanTo", "172.27.92.254");
        $this->RegisterPropertyInteger("Port", 23);

        $this->RegisterPropertyInteger("ConnectTimeoutMs", 250);
        $this->RegisterPropertyInteger("ReadTimeoutMs", 600);
        $this->RegisterPropertyBoolean("UseCRLF", true);

        $this->RegisterAttributeString("Discovered", "[]");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        if (!is_array($form)) {
            $form = array("elements" => array(), "actions" => array());
        }

        // In unserem form.json sitzt die Device-List in actions[1] (siehe unten).
        if (isset($form["actions"]) && is_array($form["actions"])) {
            foreach ($form["actions"] as $k => $a) {
                if (isset($a["name"]) && $a["name"] === "DeviceList" && isset($a["values"])) {
                    $form["actions"][$k]["values"] = $this->BuildValues();
                }
            }
        }

        return json_encode($form);
    }

    public function Scan()
    {
        IPS_LogMessage("JAPMC", "Configurator Scan() called on InstanceID=" . $this->InstanceID);


        $from = $this->ReadPropertyString("ScanFrom");
        $to   = $this->ReadPropertyString("ScanTo");
        $port = (int)$this->ReadPropertyInteger("Port");

        $cTimeout = (int)$this->ReadPropertyInteger("ConnectTimeoutMs");
        $rTimeout = (int)$this->ReadPropertyInteger("ReadTimeoutMs");
        $useCRLF  = (bool)$this->ReadPropertyBoolean("UseCRLF");

        $ips = $this->ScanRange($from, $to, $port, $cTimeout);

        $found = array();
        foreach ($ips as $ip) {
            $modelOut = $this->TelnetExec($ip, $port, $cTimeout, $rTimeout, $useCRLF, "getmodel.sh");
            $role = $this->DetectRoleFromModelOutput($modelOut); // ENC / DEC / UNKNOWN

            $webOut = $this->TelnetExec($ip, $port, $cTimeout, $rTimeout, $useCRLF, "astparam g webname");
            $web = $this->ParseWebName($webOut);

            $found[] = array(
                "IP" => $ip,
                "Role" => $role,
                "WebName" => $web,
                "ModelRaw" => trim((string)$modelOut)
            );
        }

        $this->WriteAttributeString("Discovered", json_encode($found));
        $this->SendDebug("JAPMC CFG", "Scan finished: " . count($found), 0);
    }

    private function BuildValues()
    {
        $raw = $this->ReadAttributeString("Discovered");
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            $arr = array();
        }

        $values = array();

        // Diese GUIDs müssen zu deinen module.json passen
        $encoderModuleID = "{4E0C3C4A-0C7E-4A44-9B6B-5E1C6F4A2A20}";
        $decoderModuleID = "{6D3F1D0A-7D4B-4C1C-9A1A-2B7C0E1D3F20}";

        $regID = (int)$this->EnsureRegistry();

        foreach ($arr as $row) {
            $ip   = isset($row["IP"]) ? (string)$row["IP"] : "";
            $role = isset($row["Role"]) ? (string)$row["Role"] : "UNKNOWN";
            $web  = isset($row["WebName"]) ? (string)$row["WebName"] : "";

            $encExisting = $this->FindInstanceByHost($encoderModuleID, $ip);
            $decExisting = $this->FindInstanceByHost($decoderModuleID, $ip);

            $create = array();

            if ($role === "ENC") {
                $create[] = array(
                    "moduleID" => $encoderModuleID,
                    "name" => ($web !== "") ? ("ENC " . $web) : ("ENC " . $ip),
                    "configuration" => array(
                        "Host" => $ip,
                        "Port" => (int)$this->ReadPropertyInteger("Port"),
                        "UseCRLF" => (bool)$this->ReadPropertyBoolean("UseCRLF"),
                        "RegistryInstanceID" => $regID,
                        "SourceName" => $web,
                        "AutoAssignFromSchema" => true,
                        "VideoChannel" => 0,
                        "AudioChannel" => 0,
                        "USBChannel" => 0
                    )
                );
            } elseif ($role === "DEC") {
                $create[] = array(
                    "moduleID" => $decoderModuleID,
                    "name" => ($web !== "") ? ("DEC " . $web) : ("DEC " . $ip),
                    "configuration" => array(
                        "Host" => $ip,
                        "Port" => (int)$this->ReadPropertyInteger("Port"),
                        "UseCRLF" => (bool)$this->ReadPropertyBoolean("UseCRLF"),
                        "RegistryInstanceID" => $regID
                    )
                );
            } else {
                // UNKNOWN: aus Sicherheits-/Betriebsgründen keine Auto-Anlage
            }

            $values[] = array(
                "IP" => $ip,
                "Role" => $role,
                "WebName" => $web,
                "EncoderInstanceID" => ($encExisting > 0) ? $encExisting : 0,
                "DecoderInstanceID" => ($decExisting > 0) ? $decExisting : 0,
                "create" => $create
            );
        }

        return $values;
    }

    private function DetectRoleFromModelOutput($ModelOutput)
    {
        $t = strtoupper((string)$ModelOutput);

        // robuste Heuristik
        if (strpos($t, "TRANSMITTER") !== false || strpos($t, "ENCODER") !== false) return "ENC";
        if (strpos($t, "RECEIVER") !== false || strpos($t, "DECODER") !== false) return "DEC";

        // TX/RX Tokens
        if (preg_match("/\bTX\b/", $t)) return "ENC";
        if (preg_match("/\bRX\b/", $t)) return "DEC";

        return "UNKNOWN";
    }

    private function ScanRange($FromIP, $ToIP, $Port, $ConnectTimeoutMs)
    {
        $from = ip2long($FromIP);
        $to   = ip2long($ToIP);

        if ($from === false || $to === false) {
            throw new Exception("Invalid IP range");
        }
        if ($to < $from) {
            $tmp = $from; $from = $to; $to = $tmp;
        }

        $max = 512;
        if (($to - $from + 1) > $max) {
            throw new Exception("Scan range too large (max " . $max . " IPs)");
        }

        $found = array();
        for ($i = $from; $i <= $to; $i++) {
            $ip = long2ip($i);
            if ($this->TestTcp($ip, $Port, $ConnectTimeoutMs)) {
                $found[] = $ip;
            }
        }
        return $found;
    }

    private function TestTcp($Host, $Port, $ConnectTimeoutMs)
    {
        $errno = 0;
        $errstr = "";
        $timeoutSec = max(0.05, ((int)$ConnectTimeoutMs) / 1000.0);

        $fp = @fsockopen($Host, $Port, $errno, $errstr, $timeoutSec);
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private function TelnetExec($Host, $Port, $ConnectTimeoutMs, $ReadTimeoutMs, $UseCRLF, $Command)
    {
        $errno = 0;
        $errstr = "";
        $timeoutSec = max(0.05, ((int)$ConnectTimeoutMs) / 1000.0);

        $fp = @fsockopen($Host, $Port, $errno, $errstr, $timeoutSec);
        if (!is_resource($fp)) {
            return "";
        }

        stream_set_timeout($fp, 0, max(50000, ((int)$ReadTimeoutMs) * 1000));

        // Banner
        @fread($fp, 2048);

        $cmd = $Command . ($UseCRLF ? "\r\n" : "\n");
        @fwrite($fp, $cmd);

        $buf = "";
        $start = microtime(true);
        $maxSec = max(0.05, ((int)$ReadTimeoutMs) / 1000.0);

        while ((microtime(true) - $start) < $maxSec) {
            $chunk = @fread($fp, 2048);
            if ($chunk === false || $chunk === "") {
                break;
            }
            $buf .= $chunk;
            if (strlen($buf) > 8192) {
                break;
            }
        }

        fclose($fp);
        return $buf;
    }

    private function ParseWebName($Response)
    {
        $lines = preg_split("/\r\n|\n|\r/", (string)$Response);
        foreach ($lines as $l) {
            $t = trim($l);
            if ($t === "") continue;

            if (stripos($t, "webname") !== false) {
                $parts = preg_split("/=|:/", $t);
                if (is_array($parts) && count($parts) >= 2) {
                    $candidate = trim($parts[count($parts) - 1]);
                    $candidate = trim($candidate, "\"'");
                    if ($candidate !== "") return $candidate;
                }
            } else {
                $candidate = trim($t, "\"'");
                if ($candidate !== "" && strlen($candidate) < 128) return $candidate;
            }
        }
        return "";
    }

    private function FindInstanceByHost($ModuleID, $IP)
    {
        $instances = IPS_GetInstanceListByModuleID($ModuleID);
        foreach ($instances as $id) {
            $host = (string)IPS_GetProperty($id, "Host");
            if ($host === (string)$IP) {
                return $id;
            }
        }
        return 0;
    }

    private function EnsureRegistry()
    {
        $registryModuleID = "{8C9C2A0F-9D2B-4E1A-8E1F-7A2B7A0C1E10}";
        $instances = IPS_GetInstanceListByModuleID($registryModuleID);
        if (count($instances) > 0) {
            return (int)$instances[0];
        }

        $id = IPS_CreateInstance($registryModuleID);
        IPS_SetName($id, "JAP Source Registry");
        IPS_ApplyChanges($id);
        return $id;
    }
}
