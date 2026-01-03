<?php

class JAPMCConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString("ScanFrom", "172.27.92.1");
        $this->RegisterPropertyString("ScanTo", "172.27.92.254");
        $this->RegisterPropertyInteger("Port", 23);

        $this->RegisterPropertyInteger("ConnectTimeoutMs", 250);
        $this->RegisterPropertyInteger("ReadTimeoutMs", 400);
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
        if (!is_array($form)) $form = array();

        // last element is configurator
        $idx = count($form["elements"]) - 1;
        $form["elements"][$idx]["values"] = $this->BuildValues();
        return json_encode($form);
    }

    public function Scan()
    {
        $from = $this->ReadPropertyString("ScanFrom");
        $to   = $this->ReadPropertyString("ScanTo");
        $port = (int)$this->ReadPropertyInteger("Port");

        $cTimeout = (int)$this->ReadPropertyInteger("ConnectTimeoutMs");
        $rTimeout = (int)$this->ReadPropertyInteger("ReadTimeoutMs");
        $useCRLF  = (bool)$this->ReadPropertyBoolean("UseCRLF");

        $list = $this->ScanRange($from, $to, $port, $cTimeout);

        $found = array();
        foreach ($list as $ip) {
            $web = $this->ReadWebNameViaTelnet($ip, $port, $cTimeout, $rTimeout, $useCRLF);
            $found[] = array("IP" => $ip, "WebName" => $web);
        }

        $this->WriteAttributeString("Discovered", json_encode($found));
        $this->SendDebug("JAPMC", "Scan finished: " . count($found) . " candidates", 0);
    }

    // ----- UI values -----

    private function BuildValues()
    {
        $raw = $this->ReadAttributeString("Discovered");
        $arr = json_decode($raw, true);
        if (!is_array($arr)) $arr = array();

        $values = array();
        $encoderModuleID = "{4E0C3C4A-0C7E-4A44-9B6B-5E1C6F4A2A20}";
        $decoderModuleID = "{6D3F1D0A-7D4B-4C1C-9A1A-2B7C0E1D3F20}";

        foreach ($arr as $row) {
            $ip = isset($row["IP"]) ? (string)$row["IP"] : "";
            $web = isset($row["WebName"]) ? (string)$row["WebName"] : "";

            $enc = $this->FindInstanceByHost($encoderModuleID, $ip);
            $dec = $this->FindInstanceByHost($decoderModuleID, $ip);

            $values[] = array(
                "IP" => $ip,
                "WebName" => $web,
                "EncoderInstanceID" => ($enc > 0) ? $enc : 0,
                "DecoderInstanceID" => ($dec > 0) ? $dec : 0,
                "create" => array(
                    array(
                        "moduleID" => $encoderModuleID,
                        "name" => ($web !== "") ? ("ENC " . $web) : ("ENC " . $ip),
                        "configuration" => array(
                            "Host" => $ip,
                            "Port" => (int)$this->ReadPropertyInteger("Port"),
                            "UseCRLF" => (bool)$this->ReadPropertyBoolean("UseCRLF"),
                            "RegistryInstanceID" => (int)$this->EnsureRegistry(),
                            "SourceName" => $web,
                            "AutoAssignFromSchema" => true,
                            "VideoChannel" => 0,
                            "AudioChannel" => 0,
                            "USBChannel" => 0
                        )
                    ),
                    array(
                        "moduleID" => $decoderModuleID,
                        "name" => ($web !== "") ? ("DEC " . $web) : ("DEC " . $ip),
                        "configuration" => array(
                            "Host" => $ip,
                            "Port" => (int)$this->ReadPropertyInteger("Port"),
                            "UseCRLF" => (bool)$this->ReadPropertyBoolean("UseCRLF"),
                            "RegistryInstanceID" => (int)$this->EnsureRegistry()
                        )
                    )
                )
            );
        }

        return $values;
    }

    // ----- Discovery helpers -----

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

    private function ReadWebNameViaTelnet($Host, $Port, $ConnectTimeoutMs, $ReadTimeoutMs, $UseCRLF)
    {
        $errno = 0;
        $errstr = "";
        $timeoutSec = max(0.05, ((int)$ConnectTimeoutMs) / 1000.0);

        $fp = @fsockopen($Host, $Port, $errno, $errstr, $timeoutSec);
        if (!is_resource($fp)) {
            return "";
        }

        stream_set_timeout($fp, 0, max(50000, ((int)$ReadTimeoutMs) * 1000));

        // Best effort: optional initial read
        @fread($fp, 2048);

        $cmd = "astparam g webname" . ($UseCRLF ? "\r\n" : "\n");
        @fwrite($fp, $cmd);

        // read response
        $buf = "";
        $start = microtime(true);
        while ((microtime(true) - $start) < (max(0.05, ((int)$ReadTimeoutMs) / 1000.0))) {
            $chunk = @fread($fp, 2048);
            if ($chunk === false || $chunk === "") {
                break;
            }
            $buf .= $chunk;
            if (strlen($buf) > 4096) break;
        }

        fclose($fp);

        return $this->ParseWebName($buf);
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
                if ($candidate !== "" && strlen($candidate) < 128) {
                    return $candidate;
                }
            }
        }
        return "";
    }

    // ----- Instance helpers -----

    private function FindInstanceByHost($ModuleID, $IP)
    {
        $instances = IPS_GetInstanceListByModuleID($ModuleID);
        foreach ($instances as $id) {
            $host = (string)IPS_GetProperty($id, "Host");
            if ($host === (string)$IP) return $id;
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
