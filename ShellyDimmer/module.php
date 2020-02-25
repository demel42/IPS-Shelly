<?php

declare(strict_types=1);
require_once __DIR__ . '/../libs/ShellyHelper.php';

class ShellyDimmer extends IPSModule
{
    use Shelly;
    use ShellyDimmerAction;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        $this->RegisterAttributeInteger('GatewayMode', 0); // 0 = MQTTServer 1 = MQTTClient

        $this->RegisterPropertyString('MQTTTopic', '');

        $this->RegisterVariableBoolean('Shelly_State', $this->Translate('State'), '~Switch');
        $this->RegisterVariableInteger('Shelly_Brightness', $this->Translate('Brightness'), 'Intensity.100');
        $this->RegisterVariableFloat('Shelly_Power', $this->Translate('Power'), '~Watt.3680');
        $this->RegisterVariableFloat('Shelly_Temperature', $this->Translate('Device Temperature'), '~Temperature');
        $this->RegisterVariableBoolean('Shelly_Overtemperature', $this->Translate('Overtemperature'), '');
        $this->RegisterVariableBoolean('Shelly_Overload', $this->Translate('Overload'), '');
        $this->RegisterVariableBoolean('Shelly_Loaderror', $this->Translate('Loaderror'), '');

        $this->EnableAction('Shelly_State');
        $this->EnableAction('Shelly_Brightness');
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

            if (property_exists($Buffer, 'Topic')) {
                if (fnmatch('*/light/0', $Buffer->Topic)) {
                    $this->SendDebug('Power Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Power Payload', $Buffer->Payload, 0);
                    switch ($Buffer->Payload) {
                        case 'off':
                            SetValue($this->GetIDForIdent('Shelly_State'), 0);
                            break;
                        case 'on':
                            SetValue($this->GetIDForIdent('Shelly_State'), 1);
                            break;
                    }
                }
                if (fnmatch('*status*', $Buffer->Topic)) {
                    $Payload = json_decode($Buffer->Payload);
                    SetValue($this->GetIDForIdent('Shelly_State'), $Payload->ison);
                    SetValue($this->GetIDForIdent('Shelly_Brightness'), $Payload->brightness);
                }
                if (fnmatch('*/light/0/power', $Buffer->Topic)) {
                    $this->SendDebug('Power Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Power Payload', $Buffer->Payload, 0);
                    SetValue($this->GetIDForIdent('Shelly_Power'), $Buffer->Payload);
                }
                if (fnmatch('*/temperature', $Buffer->Topic)) {
                    $this->SendDebug('Temperature Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Temperature Payload', $Buffer->Payload, 0);
                    SetValue($this->GetIDForIdent('Shelly_Temperature'), $Buffer->Payload);
                }
                if (fnmatch('*/overtemperature', $Buffer->Topic)) {
                    $this->SendDebug('Overtemperature Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Overtemperature Payload', $Buffer->Payload, 0);
                    SetValue($this->GetIDForIdent('Shelly_Overtemperature'), boolval($Buffer->Payload));
                }
                if (fnmatch('*/overload', $Buffer->Topic)) {
                    $this->SendDebug('Overload Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Overload Payload', $Buffer->Payload, 0);
                    SetValue($this->GetIDForIdent('Shelly_Overload'), $Buffer->Payload);
                }
                if (fnmatch('*/loaderror', $Buffer->Topic)) {
                    $this->SendDebug('Loaderror Topic', $Buffer->Topic, 0);
                    $this->SendDebug('Loaderror Payload', $Buffer->Payload, 0);
                    SetValue($this->GetIDForIdent('Shelly_Loaderror'), $Buffer->Payload);
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
