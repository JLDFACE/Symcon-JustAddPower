<?php

class JAPMaxColorSourceRegistry extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Schema Defaults
        $this->RegisterPropertyInteger("VideoBase", 1000);
        $this->RegisterPropertyInteger("AudioBase", 2000);
        $this->RegisterPropertyInteger("USBBase", 3000);
        $this->RegisterPropertyInteger("BlockSize", 1000);
        $this->RegisterPropertyBoolean("AllowDuplicateChannels", false);

        $this->RegisterAttributeString("LastValidation", "");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->Validate();
    }

    public function Validate()
    {
        $allowDup = $this->ReadPropertyBoolean("AllowDuplicateChannels");
        $sources = $this->BuildSourcesFromEncoders();

        $errors = array();
        $seenNames = array();
        $seenV = array();
        $seenA = array();
        $seenU = array();

        foreach ($sources as $s) {
            $name = isset($s["Name"]) ? (string)$s["Name"] : "";
            $nameKey = mb_strtolower($name);

            if ($name === "") {
                $errors[] = "Encoder has empty SourceName";
                continue;
            }
            if (isset($seenNames[$nameKey])) {
                $errors[] = "Duplicate SourceName: " . $name;
            } else {
                $seenNames[$nameKey] = true;
            }

            $v = (int)$s["Video"];
            $a = (int)$s["Audio"];
            $u = (int)$s["USB"];

            if ($v < 0 || $v > 9999) $errors[] = "Video channel out of range for " . $name . ": " . $v;
            if ($a < 0 || $a > 9999) $errors[] = "Audio channel out of range for " . $name . ": " . $a;
            if ($u < 0 || $u > 9999) $errors[] = "USB channel out of range for " . $name . ": " . $u;

            if (!$allowDup) {
                if (isset($seenV[$v])) $errors[] = "Duplicate Video channel: " . $v . " (" . $name . ")";
                if (isset($seenA[$a])) $errors[] = "Duplicate Audio channel: " . $a . " (" . $name . ")";
                if (isset($seenU[$u])) $errors[] = "Duplicate USB channel: " . $u . " (" . $name . ")";
                $seenV[$v] = true;
                $seenA[$a] = true;
                $seenU[$u] = true;
            }
        }

        $payload = array(
            "timestamp" => time(),
            "errors" => $errors,
            "count" => count($sources)
        );
        $this->WriteAttributeString("LastValidation", json_encode($payload));

        if (count($errors) > 0) {
            $this->SetStatus(104); // Warning
            $this->SendDebug("JAPMC Registry", "Validation errors: " . json_encode($errors), 0);
        } else {
            $this->SetStatus(102); // Active
            $this->SendDebug("JAPMC Registry", "Validation OK (" . count($sources) . " sources)", 0);
        }
    }

    // Public API (Wrapper-Funktionen werden von IP-Symcon erzeugt, keine eigenen Globals)
    public function RegistryGetSources()
    {
        return $this->BuildSourcesFromEncoders();
    }

    public function RegistryResolveSource($SourceName)
    {
        $nameKey = mb_strtolower((string)$SourceName);
        $sources = $this->BuildSourcesFromEncoders();
        foreach ($sources as $s) {
            $n = isset($s["Name"]) ? (string)$s["Name"] : "";
            if (mb_strtolower($n) === $nameKey) {
                return $s;
            }
        }
        return null;
    }

    public function RegistryGetNextFreeIndex()
    {
        $videoBase = $this->ReadPropertyInteger("VideoBase");
        $audioBase = $this->ReadPropertyInteger("AudioBase");
        $usbBase   = $this->ReadPropertyInteger("USBBase");
        $blockSize = $this->ReadPropertyInteger("BlockSize");

        $used = array();
        $sources = $this->BuildSourcesFromEncoders();

        foreach ($sources as $s) {
            $v = (int)$s["Video"];
            $a = (int)$s["Audio"];
            $u = (int)$s["USB"];

            // Index aus Schema ableiten (best effort)
            $nv = $v - $videoBase;
            $na = $a - $audioBase;
            $nu = $u - $usbBase;

            if ($nv >= 0 && $nv < $blockSize) $used[$nv] = true;
            if ($na >= 0 && $na < $blockSize) $used[$na] = true;
            if ($nu >= 0 && $nu < $blockSize) $used[$nu] = true;
        }

        for ($i = 0; $i < $blockSize; $i++) {
            if (!isset($used[$i])) {
                return $i;
            }
        }

        return -1;
    }

    private function BuildSourcesFromEncoders()
    {
        $encoderModuleID = "{4E0C3C4A-0C7E-4A44-9B6B-5E1C6F4A2A20}";
        $instances = IPS_GetInstanceListByModuleID($encoderModuleID);

        $sources = array();
        foreach ($instances as $id) {
            $name = (string)IPS_GetProperty($id, "SourceName");
            $v = (int)IPS_GetProperty($id, "VideoChannel");
            $a = (int)IPS_GetProperty($id, "AudioChannel");
            $u = (int)IPS_GetProperty($id, "USBChannel");

            $sources[] = array(
                "Name" => $name,
                "Video" => $v,
                "Audio" => $a,
                "USB" => $u,
                "EncoderInstanceID" => $id
            );
        }

        // sort by Name
        usort($sources, function ($x, $y) {
            $a = isset($x["Name"]) ? (string)$x["Name"] : "";
            $b = isset($y["Name"]) ? (string)$y["Name"] : "";
            return strnatcasecmp($a, $b);
        });

        return $sources;
    }
}
