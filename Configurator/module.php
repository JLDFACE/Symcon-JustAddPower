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

    // 🔑 DAS ist entscheidend
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        if (!is_array($form)) {
            return "{}";
        }

        if (isset($form["actions"]) && is_array($form["actions"])) {
            foreach ($form["actions"] as $idx => $action) {
                if (
                    isset($action["type"], $action["name"]) &&
                    $action["type"] === "Configurator" &&
                    $action["name"] === "DeviceList"
                ) {
                    $form["actions"][$idx]["values"] = $this->BuildValues();
                }
            }
        }

        return json_encode($form);
    }

    public function Scan()
    {
        IPS_LogMessage("JAPMC CFG", "Scan() gestartet, Instanz " . $this->InstanceID);
        IPS_LogMessage("JAPMC", "Scan() called. InstanceID=" . $this->InstanceID);


        $from = ip2long($this->ReadPropertyString("ScanFrom"));
        $to   = ip2long($this->ReadPropertyString("ScanTo"));
        $port = (int)$this->ReadPropertyInteger("Port");

        if ($from === false || $to === false) {
            throw new Exception("Ungültiger IP-Bereich");
        }
        if ($to < $from) {
            $tmp = $from; $from = $to; $to = $tmp;
        }

        $found = [];

        for ($i = $from; $i <= $to; $i++) {
            $ip = long2ip($i);

            $fp = @fsockopen($ip, $port, $e, $s, 0.2);
            if (!is_resource($fp)) {
                continue;
            }

            stream_set_timeout($fp, 0, 500000);
            fread($fp, 2048);

            fwrite($fp, "getmodel.sh\n");
            $model = fread($fp, 2048);

            fwrite($fp, "astparam g webname\n");
            $web = fread($fp, 2048);

            fclose($fp);

            $role = "UNKNOWN";
            $m = strtoupper($model);
            if (strpos($m, "TX") !== false || strpos($m, "ENCODER") !== false) $role = "ENC";
            if (strpos($m, "RX") !== false || strpos($m, "DECODER") !== false) $role = "DEC";

            $webName = trim(preg_replace('/.*=/', '', $web));

            $found[] = [
                "IP" => $ip,
                "Role" => $role,
                "WebName" => $webName,
                "EncoderInstanceID" => 0,
                "DecoderInstanceID" => 0,
                "create" => []   // wichtig: darf leer sein
            ];
        }
        $this->SendDebug("JAPMC CFG", "Discovered=" . json_encode($found), 0);

        $this->WriteAttributeString("Discovered", json_encode($found));
        IPS_LogMessage("JAPMC CFG", "Scan beendet, gefunden: " . count($found));
    }

    private function BuildValues()
    {
        $raw = $this->ReadAttributeString("Discovered");
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return [];
        }
        return $arr;
    }
}
