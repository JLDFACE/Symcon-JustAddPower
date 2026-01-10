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

    /* --------------------------------------------------------------------- */
    /* Form handling                                                         */
    /* --------------------------------------------------------------------- */

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        if (!is_array($form)) {
            $form = ["elements" => [], "actions" => []];
        }

        $values = $this->BuildValues();
        $form   = $this->InjectConfiguratorValues($form, "DeviceList", $values);

        return json_encode($form);
    }

    private function InjectConfiguratorValues($form, $name, $values)
    {
        foreach (["elements", "actions"] as $section) {
            if (!isset($form[$section]) || !is_array($form[$section])) {
                continue;
            }

            foreach ($form[$section] as $k => $e) {
                if (
                    isset($e["type"], $e["name"]) &&
                    $e["type"] === "Configurator" &&
                    $e["name"] === $name
                ) {
                    $form[$section][$k]["values"] = $values;
                }
            }
        }
        return $form;
    }

    /* --------------------------------------------------------------------- */
    /* Scan                                                                  */
    /* --------------------------------------------------------------------- */

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

        $found = [];

        foreach ($ips as $ip) {
            $model = $this->TelnetExec($ip, $port, $cTimeout, $rTimeout, $useCRLF, "getmodel.sh");
            $role  = $this->DetectRoleFromModel($model);

            $web   = $this->TelnetExec($ip, $port, $cTimeout, $rTimeout, $useCRLF, "astparam g webname");
            $web   = $this->ParseWebName($web);

            $found[] = [
                "IP"       => $ip,
                "Role"     => $role,
                "WebName"  => $web,
                "ModelRaw" => trim((string)$model)
            ];
        }

        $this->WriteAttributeString("Discovered", json_encode($found));
        $this->SendDebug("JAPMC CFG", "Discovered=" . json_encode($found), 0);
        IPS_LogMessage("JAPMC CFG", "Scan beendet, gefunden: " . count($found));
    }

    /* --------------------------------------------------------------------- */
    /* Value builder for Configurator                                        */
    /* --------------------------------------------------------------------- */

    private function BuildValues()
    {
        $raw = json_decode($this->ReadAttributeString("Discovered"), true);
        if (!is_array($raw)) {
            return [];
        }

        $encoderModuleID = "{4E0C3C4A-0C7E-4A44-9B6B-5E1C6F4A2A20}";
        $decoderModuleID = "{6D3F1D0A-7D4B-4C1C-9A1A-2B7C0E1D3F20}";

        $values = [];

        foreach ($raw as $r) {
            $ip   = (string)$r["IP"];
            $role = (string)$r["Role"];
            $web  = (string)$r["WebName"];

            $values[] = [
                "IP"                 => $ip,
                "Role"               => $role,
                "WebName"            => $web,
                "ModelRaw"           => (string)$r["ModelRaw"],
                "EncoderInstanceID"  => $this->FindInstanceByHost($encoderModuleID, $ip),
                "DecoderInstanceID"  => $this->FindInstanceByHost($decoderModuleID, $ip),
                "create"             => $this->BuildCreateArray($role, $ip, $web)
            ];
        }

        return $values;
    }

    private function BuildCreateArray($role, $ip, $web)
    {
        $regID = $this->EnsureRegistry();

        if ($role === "ENC") {
            return [[
                "moduleID" => "{4E0C3C4A-0C7E-4A44-9B6B-5E1C6F4A2A20}",
                "name"     => "ENC " . ($web !== "" ? $web : $ip),
                "configuration" => [
                    "Host"                  => $ip,
                    "Port"                  => 23,
                    "RegistryInstanceID"    => $regID,
                    "SourceName"            => $web,
                    "AutoAssignFromSchema"  => true
                ]
            ]];
        }

        if ($role === "DEC") {
            return [[
                "moduleID" => "{6D3F1D0A-7D4B-4C1C-9A1A-2B7C0E1D3F20}",
                "name"     => "DEC " . ($web !== "" ? $web : $ip),
                "configuration" => [
                    "Host"               => $ip,
                    "Port"               => 23,
                    "RegistryInstanceID" => $regID
                ]
            ]];
        }

        return [];
    }

    /* --------------------------------------------------------------------- */
    /* Detection & Helpers                                                   */
    /* --------------------------------------------------------------------- */

    private function DetectRoleFromModel($model)
    {
        $t = strtoupper((string)$model);

        if (preg_match("/\bRX\b/", $t)) return "DEC";
        if (preg_match("/\bTX\b/", $t)) return "ENC";

        return "UNKNOWN";
    }

    private function ScanRange($fromIP, $toIP, $port, $timeoutMs)
    {
        $from = ip2long($fromIP);
        $to   = ip2long($toIP);

        if ($from === false || $to === false) {
            return [];
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $found = [];
        for ($i = $from; $i <= $to && count($found) < 512; $i++) {
            $ip = long2ip($i);
            if ($this->TestTcp($ip, $port, $timeoutMs)) {
                $found[] = $ip;
            }
        }
        return $found;
    }

    private function TestTcp($host, $port, $timeoutMs)
    {
        $fp = @fsockopen($host, $port, $e, $s, max(0.05, $timeoutMs / 1000));
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private function TelnetExec($host, $port, $cTimeout, $rTimeout, $useCRLF, $cmd)
    {
        $fp = @fsockopen($host, $port, $e, $s, max(0.05, $cTimeout / 1000));
        if (!is_resource($fp)) {
            return "";
        }

        stream_set_timeout($fp, 0, max(50000, $rTimeout * 1000));
        @fread($fp, 2048);

        @fwrite($fp, $cmd . ($useCRLF ? "\r\n" : "\n"));

        $buf = "";
        $start = microtime(true);
        while ((microtime(true) - $start) < max(0.05, $rTimeout / 1000)) {
            $chunk = @fread($fp, 2048);
            if ($chunk === "" || $chunk === false) break;
            $buf .= $chunk;
        }

        fclose($fp);
        return $buf;
    }

    private function ParseWebName($txt)
    {
        foreach (preg_split("/\r\n|\n|\r/", (string)$txt) as $l) {
            if (stripos($l, "webname") !== false) {
                $p = explode("=", $l, 2);
                return trim(end($p), "\"' ");
            }
        }
        return "";
    }

    private function FindInstanceByHost($moduleID, $ip)
    {
        foreach (IPS_GetInstanceListByModuleID($moduleID) as $id) {
            if (IPS_GetProperty($id, "Host") === $ip) {
                return $id;
            }
        }
        return 0;
    }

    private function EnsureRegistry()
    {
        $regID = "{8C9C2A0F-9D2B-4E1A-8E1F-7A2B7A0C1E10}";
        $list = IPS_GetInstanceListByModuleID($regID);

        if (count($list) > 0) {
            return (int)$list[0];
        }

        $id = IPS_CreateInstance($regID);
        IPS_SetName($id, "JAP Source Registry");
        IPS_ApplyChanges($id);
        return $id;
    }
}
