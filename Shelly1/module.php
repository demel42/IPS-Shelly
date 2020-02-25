<?php

declare(strict_types=1);
require_once __DIR__ . '/../libs/ShellyHelper.php';

class Shelly1 extends IPSModule
{
    use Shelly;
    use
        ShellyRelayAction;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        $this->RegisterAttributeInteger('GatewayMode', 0); // 0 = MQTTServer 1 = MQTTClient

        $this->RegisterPropertyString('MQTTTopic', '');
        $this->RegisterPropertyString('Device', '');
        $this->RegisterVariableBoolean('Shelly_State', $this->Translate('State'), '~Switch');

        $this->EnableAction('Shelly_State');

        $this->RegisterVariableBoolean('Shelly_Input', $this->Translate('Input'), '~Switch');
        $this->RegisterVariableBoolean('Shelly_Longpush', $this->Translate('Longpush'), '~Switch');
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        parent::ApplyChanges();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        //Setze Filter für ReceiveData
        $MQTTTopic = $this->ReadPropertyString('MQTTTopic');
        $this->SetReceiveDataFilter('.*' . $MQTTTopic . '.*');

        if (($this->ReadPropertyString('Device') == 'shelly1pm')) {
            $this->RegisterVariableFloat('Shelly_Power', $this->Translate('Power'), '~Watt.3680');
            $this->RegisterVariableBoolean('Shelly_Overtemperature', $this->Translate('Overtemperature'), '');
            $this->RegisterVariableFloat('Shelly_Temperature', $this->Translate('Temperature'), '~Temperature');
            $this->RegisterVariableFloat('Shelly_Energy', $this->Translate('Energy'), '~Electricity');
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case FM_CONNECT:
                //$this->LogMessage('parentGUID '. print_r($Data),KL_DEBUG);
                $parentGUID = IPS_GetInstance($Data[0])['ModuleInfo']['ModuleID'];
                switch ($parentGUID) {
                    case '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}':
                        $this->WriteAttributeInteger('GatewayMode', 0);
                        break;
                    case '{EE0D345A-CF31-428A-A613-33CE98E752DD}':
                        $this->WriteAttributeInteger('GatewayMode', 1);
                        break;
                }
                break;
            default:
                break;
        }
    }

    public function ReceiveData($JSONString)
    {
        $GatewayMode = $this->ReadAttributeInteger('GatewayMode');
        $this->SendDebug('JSON', $JSONString, 0);
        if (!empty($this->ReadPropertyString('MQTTTopic'))) {
            $data = json_decode($JSONString);

            $this->SendDebug('GatewayMode', $GatewayMode, 0);
            if ($GatewayMode == 0) {
                $Buffer = $data;
            } else {
                $Buffer = json_decode($data->Buffer);
            }

            $this->SendDebug('MQTT Topic', $Buffer->Topic, 0);

            //Power Variable prüfen
            if (property_exists($Buffer, 'Topic')) {
                //Ist es ein Shell1y1? Wenn ja weiter machen!
                if (fnmatch('*/relay/0', $Buffer->Topic)) {
                    $this->SendDebug('Power Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Power Payload', $Buffer->Payload, 0);
                    //Power prüfen und in IPS setzen
                    switch ($Buffer->Payload) {
                        case 'off':
                            SetValue($this->GetIDForIdent('Shelly_State'), 0);
                            break;
                        case 'on':
                            SetValue($this->GetIDForIdent('Shelly_State'), 1);
                            break;
                    }
                }
                if (fnmatch('*/input/0', $Buffer->Topic)) {
                    $this->SendDebug('Input Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Input Payload', $Buffer->Payload, 0);
                    switch ($Buffer->Payload) {
                        case 0:
                            SetValue($this->GetIDForIdent('Shelly_Input'), 0);
                            break;
                        case 1:
                            SetValue($this->GetIDForIdent('Shelly_Input'), 1);
                            break;
                    }
                }
                if (fnmatch('*/longpush/0', $Buffer->Topic)) {
                    $this->SendDebug('Longpush Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Longpush Payload', $Buffer->Payload, 0);
                    switch ($Buffer->Payload) {
                        case 0:
                            SetValue($this->GetIDForIdent('Shelly_Longpush'), 0);
                            break;
                        case 1:
                            SetValue($this->GetIDForIdent('Shelly_Longpush'), 1);
                            break;
                    }
                }
                if (fnmatch('*/temperature', $Buffer->Topic)) {
                    $this->SendDebug('Power Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Power Payload', $Buffer->Payload, 0);
                    SetValue($this->GetIDForIdent('Shelly_Temperature'), $Buffer->Payload);
                }
                if (fnmatch('*/overtemperature', $Buffer->Topic)) {
                    $this->SendDebug('Power Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Power Payload', $Buffer->Payload, 0);
                    SetValue($this->GetIDForIdent('Shelly_Overtemperature'), boolval($Buffer->Payload));
                }
                if (fnmatch('*/relay/0/power', $Buffer->Topic)) {
                    $this->SendDebug('Power Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Power Payload', $Buffer->Payload, 0);
                    SetValue($this->GetIDForIdent('Shelly_Power'), $Buffer->Payload);
                }
                if (fnmatch('*/relay/0/energy*', $Buffer->Topic)) {
                    $this->SendDebug('Energy Payload', $Buffer->Payload, 0);
                    SetValue($this->GetIDForIdent('Shelly_Energy'), $Buffer->Payload / 60000);
                }
            }
        }
    }

    private function RegisterProfileBoolean($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize)
    {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 0);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 0) {
                throw new Exception($this->Translate('Variable profile type does not match for profile') . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
    }

    private function RegisterProfileBooleanEx($Name, $Icon, $Prefix, $Suffix, $Associations)
    {
        if (count($Associations) === 0) {
            $MinValue = 0;
            $MaxValue = 0;
        } else {
            $MinValue = $Associations[0][0];
            $MaxValue = $Associations[count($Associations) - 1][0];
        }

        $this->RegisterProfileBoolean($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, 0);

        foreach ($Associations as $Association) {
            IPS_SetVariableProfileAssociation($Name, $Association[0], $Association[1], $Association[2], $Association[3]);
        }
    }
}
