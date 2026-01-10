<?php

class JAPMaxColorConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString("ScanFrom", "192.168.10.1");
        $this->RegisterPropertyString("ScanTo", "192.168.10.254");
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

        if (isset($form["actions"]) && is_array($form["actions"])) {
            foreach ($form["actions"] as $k => $a) {
                if (isset($a["type"]) && $a["type"] === "Configurator" && isset($a["name"]) && $a["name"] === "DeviceList") {
                    $form["actions"][$k]["values"] = $this->BuildValues();
                }
            }
        }

        return json_encode($form);
    }

    public function Scan()
    {
        IPS_LogMessage("JAPMC CFG", "Scan() gestartet, Instanz " . $this->InstanceID);

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

// Extrahiere z.B. "MC-RX1" oder "MC-TX2" aus beliebiger Ausgabe (Prompt/Echo/Mehrzeiler)
$modelToken = $this->ExtractModelToken($modelOut);

// Debug in Meldungen (sichtbar ohne Debug-Fenster)
IPS_LogMessage("JAPMC CFG", "Probe " . $ip . " getmodel.sh raw=" . $this->OneLine($modelOut) . " token=" . $modelToken);

if ($modelToken === "") {
    continue; // kein MaxColor gefunden
}

$role = $this->DetectRoleFromModelOutput($modelToken);

            // 4) WebName holen (kann je nach Firmware "webname=..." oder nur der Wert sein)
            $webOut = $this->TelnetExec($ip, $port, $cTimeout, $rTimeout, $useCRLF, "astparam g webname");
            $web = $this->ParseWebName($webOut);

            $found[] = array(
                "IP" => $ip,
                "Role" => $role,
                "WebName" => $web,
                "ModelRaw" => $model,
                "RoleOverride" => ""
            );
        }

        $this->WriteAttributeString("Discovered", json_encode($found));
        IPS_LogMessage("JAPMC CFG", "Discovered=" . $this->OneLine(json_encode($found)));


        IPS_LogMessage("JAPMC CFG", "Scan beendet, gefunden: " . count($found));
        $this->SendDebug("JAPMC CFG", "Discovered=" . json_encode($found), 0);
    }

    private function BuildValues()
    {
        $raw = $this->ReadAttributeString("Discovered");
        $arr = json_decode($raw, true);
        if (!is_array($arr)) $arr = array();

        // GUIDs müssen zu euren module.json passen:
        $encoderModuleID = "{4E0C3C4A-0C7E-4A44-9B6B-5E1C6F4A2A20}";
        $decoderModuleID = "{6D3F1D0A-7D4B-4C1C-9A1A-2B7C0E1D3F20}";

        $regID = (int)$this->EnsureRegistry();

        $values = array();

        foreach ($arr as $row) {
            $ip   = isset($row["IP"]) ? (string)$row["IP"] : "";
            $role = isset($row["Role"]) ? (string)$row["Role"] : "UNKNOWN";
            $web  = isset($row["WebName"]) ? (string)$row["WebName"] : "";
            $modelRaw = isset($row["ModelRaw"]) ? (string)$row["ModelRaw"] : "";
            $override = isset($row["RoleOverride"]) ? (string)$row["RoleOverride"] : "";

            // Sanity WebName
            $low = mb_strtolower(trim($web));
            if ($low === "getmodel.sh" || $low === "getmodel" || $low === "astparam" || $low === "channel") $web = "";

            // Override auswerten
            if ($override === "ENC" || $override === "DEC") {
                $role = $override;
            } elseif ($override === "SKIP") {
                $role = "UNKNOWN";
            }

            $encExisting = $this->FindInstanceByHost($encoderModuleID, $ip);
            $decExisting = $this->FindInstanceByHost($decoderModuleID, $ip);

            // instanceID setzen, falls bereits vorhanden
            $instanceID = 0;
            if ($role === "ENC" && $encExisting > 0) $instanceID = $encExisting;
            if ($role === "DEC" && $decExisting > 0) $instanceID = $decExisting;

            $rowOut = array(
                "IP" => $ip,
                "Role" => $role,
                "WebName" => $web,
                "ModelRaw" => $modelRaw,
                "EncoderInstanceID" => ($encExisting > 0) ? $encExisting : 0,
                "DecoderInstanceID" => ($decExisting > 0) ? $decExisting : 0,
                "RoleOverride" => $override,
                "instanceID" => $instanceID
            );

            // create nur anbieten, wenn keine Instanz existiert (und nicht SKIP)
            if ($instanceID == 0 && $override !== "SKIP") {
                if ($role === "ENC") {
                    $rowOut["create"] = array(
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
                    $rowOut["create"] = array(
                        "moduleID" => $decoderModuleID,
                        "name" => ($web !== "") ? ("DEC " . $web) : ("DEC " . $ip),
                        "configuration" => array(
                            "Host" => $ip,
                            "Port" => (int)$this->ReadPropertyInteger("Port"),
                            "UseCRLF" => (bool)$this->ReadPropertyBoolean("UseCRLF"),
                            "RegistryInstanceID" => $regID
                        )
                    );
                }
            }

            $values[] = $rowOut;
        }

        return $values;
    }

    private function DetectRoleFromModelOutput($ModelOutput)
    {
        $t = strtoupper(trim((string)$ModelOutput));
        if ($t === "") return "UNKNOWN";

        // Robust für viele Varianten: MC-RX1, MC-TX2, MC-RX0589C9, MC-TX00ABCD, ...
        if (preg_match('/\bMC-[A-Z0-9_-]*RX[A-Z0-9_-]*\b/', $t)) return "DEC";
        if (preg_match('/\bMC-[A-Z0-9_-]*TX[A-Z0-9_-]*\b/', $t)) return "ENC";

        if (strpos($t, "RECEIVER") !== false || strpos($t, "DECODER") !== false) return "DEC";
        if (strpos($t, "TRANSMITTER") !== false || strpos($t, "ENCODER") !== false) return "ENC";

        if (preg_match('/\bRX\b/', $t)) return "DEC";
        if (preg_match('/\bTX\b/', $t)) return "ENC";

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

    private function ExtractModelToken($ModelOutput)
{
    $t = strtoupper((string)$ModelOutput);

    // sucht "MC-..." irgendwo in der Ausgabe und nimmt das erste passende Token
    if (preg_match('/\bMC-[A-Z0-9_-]+\b/', $t, $m)) {
        return trim($m[0]);
    }
    return "";
}

private function OneLine($Text)
{
    $s = trim((string)$Text);
    $s = preg_replace("/\s+/", " ", $s);
    if (strlen($s) > 160) $s = substr($s, 0, 160) . "...";
    return $s;
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
        if (!is_resource($fp)) return "";

        stream_set_timeout($fp, 0, max(50000, ((int)$ReadTimeoutMs) * 1000));

        // Banner/Prompt "weglesen"
        @fread($fp, 2048);

        $cmd = $Command . ($UseCRLF ? "\r\n" : "\n");
        @fwrite($fp, $cmd);

        $buf = "";
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

    private function ParseWebName($Response)
    {
        $lines = preg_split("/\r\n|\n|\r/", (string)$Response);

        // 1) bevorzugt "webname=..." Zeile
        foreach ($lines as $l) {
            $t = trim($l);
            if ($t === "") continue;

            if (stripos($t, "webname") !== false) {
                $parts = preg_split("/=|:/", $t);
                if (is_array($parts) && count($parts) >= 2) {
                    $candidate = trim($parts[count($parts) - 1]);
                    $candidate = trim($candidate, "\"' \t");
                    if ($this->IsPlausibleName($candidate)) return $candidate;
                }
            }ƒ
        }

        // 2) fallback: nur der Wert (typisch bei "astparam g webname")
        foreach ($lines as $l) {
            $t = trim($l);
            if ($this->IsPlausibleName($t)) return $t;
        }

        return "";
    }

    private function IsPlausibleName($Name)
    {
        $n = trim((string)$Name);
        if ($n === "") return false;
        if (strlen($n) > 80) return false;

        $low = mb_strtolower($n);
        if ($low === "getmodel.sh" || $low === "getmodel" || $low === "astparam" || $low === "channel") return false;

        if (!preg_match("/^[A-Za-z0-9 _\\-\\.]+$/", $n)) return false;

        return true;
    }

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
        if (count($instances) > 0) return (int)$instances[0];

        $id = IPS_CreateInstance($registryModuleID);
        IPS_SetName($id, "JAP Source Registry");
        IPS_ApplyChanges($id);
        return $id;
    }
}
