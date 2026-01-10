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
        $this->RegisterPropertyInteger("ReadTimeoutMs", 800);
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
            $model = strtoupper(trim((string)$this->ParseSingleLineValue($modelOut, "getmodel.sh")));

            // Filter: nur echte MaxColor
            if ($model === "" || strpos($model, "MC-") !== 0) {
                continue;
            }

            $role = $this->DetectRoleFromModelOutput($model);

            $webOut = $this->TelnetExec($ip, $port, $cTimeout, $rTimeout, $useCRLF, "astparam g webname");
            $web = $this->ParseValueFromAstparamGet($webOut, "astparam g webname");

            $this->SendDebug("JAPMC CFG", "RAW webname (" . $ip . ")=" . json_encode($webOut), 0);

            $found[] = array(
                "IP" => $ip,
                "Role" => $role,
                "WebName" => $web,
                "ModelRaw" => $model,
                "RoleOverride" => ""
            );
        }

        $this->WriteAttributeString("Discovered", json_encode($found));
        IPS_LogMessage("JAPMC CFG", "Scan beendet, gefunden: " . count($found));
        $this->SendDebug("JAPMC CFG", "Discovered=" . json_encode($found), 0);

        // KEIN UpdateFormField -> versionskompatibel
        // Nutzer klickt "Ansicht aktualisieren" oder öffnet Form neu
    }

    // Nur um die Form sauber neu zu laden (Button)
    public function ReloadForm()
    {
        // bewusst leer – das Aufrufen dieser Funktion reicht, damit die Konsole die Form neu abfragt
        IPS_LogMessage("JAPMC CFG", "ReloadForm() requested");
    }

    private function BuildValues()
    {
        $raw = $this->ReadAttributeString("Discovered");
        $arr = json_decode($raw, true);
        if (!is_array($arr)) $arr = array();

        // GUIDs müssen zu euren module.json passen
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

            if ($override === "ENC" || $override === "DEC") $role = $override;
            if ($override === "SKIP") $role = "UNKNOWN";

            $encExisting = $this->FindInstanceByHost($encoderModuleID, $ip);
            $decExisting = $this->FindInstanceByHost($decoderModuleID, $ip);

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

        if (preg_match('/\bMC-[A-Z0-9_-]*RX[A-Z0-9_-]*\b/', $t)) return "DEC";
        if (preg_match('/\bMC-[A-Z0-9_-]*TX[A-Z0-9_-]*\b/', $t)) return "ENC";

        return "UNKNOWN";
    }

    private function ParseValueFromAstparamGet($Response, $CommandEcho)
    {
        $lines = $this->CleanTelnetLines($Response, $CommandEcho);

        foreach ($lines as $t) {
            if (strpos($t, "=") !== false) {
                $parts = explode("=", $t, 2);
                $candidate = trim($parts[1]);
                $candidate = trim($candidate, "\"' \t");
                if ($this->IsPlausibleName($candidate)) return $candidate;
            }

            $candidate = trim($t, "\"' \t");
            if ($this->IsPlausibleName($candidate)) return $candidate;
        }

        return "";
    }

    private function ParseSingleLineValue($Response, $CommandEcho)
    {
        $lines = $this->CleanTelnetLines($Response, $CommandEcho);
        foreach ($lines as $t) {
            if ($t !== "" && strlen($t) <= 80) return $t;
        }
        return "";
    }

    private function CleanTelnetLines($Response, $CommandEcho)
    {
        $echo = mb_strtolower(trim((string)$CommandEcho));
        $rawLines = preg_split("/\r\n|\n|\r/", (string)$Response);
        $out = array();

        foreach ($rawLines as $l) {
            $t = trim($l);
            if ($t === "") continue;

            $low = mb_strtolower($t);
            if ($echo !== "" && ($low === $echo || strpos($low, $echo) !== false)) continue;

            // Prompt-Suffix abschneiden (kommt bei euch am selben String)
            $t = preg_replace("/\\s*\\/usr\\/local\\/bin\\s*#\\s*$/", "", $t);
            $t = preg_replace("/\\s*[>#\\$]\\s*$/", "", $t);
            $t = trim($t);
            if ($t === "") continue;

            // Standalone prompt entfernen
            if (preg_match("/^\\/?usr\\/local\\/bin\\s*#$/", $t)) continue;
            if (preg_match("/^[>#\\$]$/", $t)) continue;

            $out[] = $t;
        }

        return $out;
    }

    private function IsPlausibleName($Name)
    {
        $n = trim((string)$Name);
        if ($n === "") return false;
        if (strlen($n) > 80) return false;

        $low = mb_strtolower($n);
        if (strpos($low, "astparam") !== false) return false;
        if (strpos($low, "getmodel") !== false) return false;
        if (strpos($low, "channel") !== false) return false;
        if (substr($low, -3) === ".sh") return false;

        if (!preg_match("/^[A-Za-z0-9 _\\-\\.]+$/", $n)) return false;
        return true;
    }

    private function ScanRange($FromIP, $ToIP, $Port, $ConnectTimeoutMs)
    {
        $from = ip2long($FromIP);
        $to   = ip2long($ToIP);
        if ($from === false || $to === false) throw new Exception("Invalid IP range");
        if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }

        $max = 512;
        if (($to - $from + 1) > $max) throw new Exception("Scan range too large (max " . $max . " IPs)");

        $found = array();
        for ($i = $from; $i <= $to; $i++) {
            $ip = long2ip($i);
            if ($this->TestTcp($ip, $Port, $ConnectTimeoutMs)) $found[] = $ip;
        }
        return $found;
    }

    private function TestTcp($Host, $Port, $ConnectTimeoutMs)
    {
        $errno = 0; $errstr = "";
        $timeoutSec = max(0.05, ((int)$ConnectTimeoutMs) / 1000.0);
        $fp = @fsockopen($Host, $Port, $errno, $errstr, $timeoutSec);
        if (is_resource($fp)) { fclose($fp); return true; }
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
