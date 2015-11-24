<?php



/** @ignore */
class CallContext
{
    public $arguments = Array();

    /** @var  Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object  */
    public $object;

    public $actionRef;

    public $isAPI = false;

    /** @var  $baseObject PANConf|PanoramaConf */
    public $baseObject;

    /** @var  $subSystem VirtualSystem|PANConf|PanoramaConf|DeviceGroup */
    public $subSystem;

    /** @var PanAPIConnector */
    public $connector = null;

    public $padding = '';

    public $nestedQueries;

    public function __construct($actionProperties, $arguments, $nestedQueries = null)
    {
        $this->actionRef = $actionProperties;
        $this->prepareArgumentsForAction($arguments);

        if( $nestedQueries === null )
            $nestedQueries = Array();
        else
            $this->nestedQueries = &$nestedQueries;
    }

    /**
     * @param $object Address|AddressGroup|Service|ServiceGroup|Rule
     */
    public function executeAction($object)
    {
        $this->object = $object;

        print "   - object '" . PH::boldText($object->name()) . "' passing through Action='{$this->actionRef['name']}'";

        if( count($this->arguments) != 0 )
        {
            print " Args: ";
            foreach($this->arguments as $argName => $argValue)
            {
                if( is_bool($argValue) )
                    print "$argName=".boolYesNo($argValue).", ";
                else
                    print "$argName=$argValue, ";
            }
        }

        print "\n";

        $this->actionRef['MainFunction']($this);
    }

    public function hasGlobalFinishAction()
    {
        return isset($this->actionRef['GlobalFinishFunction']);
    }

    public function executeGlobalFinishAction()
    {
        print "   - action '{$this->actionRef['name']}' has tasks to process before shutdown.\n";
        $this->actionRef['GlobalFinishFunction']($this);
    }

    public function prepareArgumentsForAction($arguments)
    {
        $this->arguments = Array();

        if(strlen($arguments) != 0 && !isset($this->actionRef['args']) )
            display_error_usage_exit("error while processing argument '{$this->actionRef['name']}' : arguments were provided while they are not supported by this action");

        if(!isset($this->actionRef['args']) || $this->actionRef['args'] === false )
            return;

        $ex = explode(',', $arguments);

        if( count($ex) > count($this->actionRef['args']) )
            display_error_usage_exit("error while processing argument '{$this->actionRef['name']}' : too many arguments provided");

        $count = -1;
        foreach( $this->actionRef['args'] as $argName => &$properties )
        {
            $count++;

            $argValue = null;
            if( isset($ex[$count]) )
                $argValue = $ex[$count];


            if( (!isset($properties['default']) || $properties['default'] == '*nodefault*') && ($argValue === null || strlen($argValue)) == 0 )
                derr("action '{$this->actionRef['name']}' argument#{$count} '{$argName}' requires a value, it has no default one");

            if( $argValue !== null && strlen($argValue) > 0)
                $argValue = trim($argValue);
            else
                $argValue = $properties['default'];

            if( $properties['type'] == 'string' )
            {
                if( isset( $properties['choices']) )
                {
                    foreach($properties['choices'] as $choice )
                    {
                        $tmpChoice[strtolower($choice)] = true;
                    }
                    $argValue = strtolower($argValue);
                    if( !isset($tmpChoice[$argValue]) )
                        derr("unsupported value '{$argValue}' for action '{$this->actionRef['name']}' arg#{$count} '{$argName}'");
                }
            }
            elseif( $properties['type'] == 'boolean' || $properties['type'] == 'bool' )
            {
                if( $argValue == '1' || strtolower($argValue) == 'true' || strtolower($argValue) == 'yes' )
                    $argValue = true;
                elseif( $argValue == '0' || strtolower($argValue) == 'false' || strtolower($argValue) == 'no' )
                    $argValue = false;
                else
                    derr("unsupported argument value '{$argValue}' which should of type '{$properties['type']}' for  action '{$this->actionRef['name']}' arg#{$count} helper#'{$argName}'");
            }
            elseif( $properties['type'] == 'integer' )
            {
                if( !is_integer($argValue) )
                    derr("unsupported argument value '{$argValue}' which should of type '{$properties['type']}' for  action '{$this->actionRef['name']}' arg#{$count} helper#'{$argName}'");
            }
            else
            {
                derr("unsupported argument type '{$properties['type']}' for  action '{$this->actionRef['name']}' arg#{$count} helper#'{$argName}'");
            }
            $this->arguments[$argName] = $argValue;
        }

    }

    public function toString()
    {
        $ret = '';

        $ret .= "Action:'{$this->actionRef['name']}'";

        if( count($this->arguments) != 0 )
        {
            $ret .= " / Args: ";
            foreach($this->arguments as $argName => $argValue)
            {
                if( is_bool($argValue) )
                    $ret .= "$argName=".boolYesNo($argValue).", ";
                else
                    $ret .= "$argName=$argValue, ";
            }
        }

        return $ret;
    }
}


class RuleCallContext extends CallContext
{

    public static $commonActionFunctions = Array();
    public static $supportedActions = Array();

    public function addRuleToMergedApiChange($setValue)
    {
        $rule = $this->object;

        if( !isset($this->mergeArray) )
            $this->mergeArray = Array();

        $mergeArray = &$this->mergeArray;
        $panoramaMode = $this->baseObject->isPanorama();
        $subSystem = $this->subSystem;


        $classToType = Array('SecurityRule' => 'security', 'NatRule' => 'nat', );
        $type = $classToType[get_class($rule)];

        if( !$panoramaMode )
        {
            $mergeArray[$subSystem->name()][$type][$rule->name()] = $setValue;
            return;
        }

        $ruleLocation = 'pre-rulebase';
        if( $rule->isPostRule() )
            $ruleLocation = 'post-rulebase';

        if( $rule->owner->owner->isPanorama() )
            $mergeArray['shared'][$ruleLocation][$type][$rule->name()] = $setValue;
        else
            $mergeArray[$subSystem->name()][$ruleLocation][$type][$rule->name()] = $setValue;
    }


    public function generateRuleMergedApuChangeString($forSharedRules=false)
    {

        if( !isset($this->mergeArray) )
            return null;

        $mergeArray = &$this->mergeArray;

        if( count($mergeArray) < 1 )
            return null;

        if( $this->baseObject->isPanorama() )
        {
            $strPointer = '';

            if( $forSharedRules && !isset($mergeArray['shared']) )
                return null;

            foreach($mergeArray as $subSystemName => &$locations)
            {
                if( $subSystemName == 'shared' )
                {
                    if( !$forSharedRules )
                        continue;
                }
                else
                {
                    if( $forSharedRules )
                        continue;
                }

                if( !$forSharedRules )
                    $strPointer .= "<entry name=\"{$subSystemName}\">";

                foreach($locations as $locationName => &$types)
                {
                    $strPointer .= "<{$locationName}>";

                    foreach($types as $typeName => &$rules)
                    {
                        $strPointer .= "<{$typeName}><rules>\n";

                        foreach($rules as $ruleName => $xmlValue )
                        {
                            $strPointer .= "<entry name=\"{$ruleName}\">{$xmlValue}</entry>\n";
                        }

                        $strPointer .= "</rules></{$typeName}>\n";
                    }

                    $strPointer .= "</{$locationName}>";
                }

                if( !$forSharedRules )
                    $strPointer .= "</entry>";
            }

            if( $forSharedRules )
                return $strPointer;

            if( strlen($strPointer) < 1 )
                return null;

            return '<device-group>'.$strPointer.'</device-group>';
        }
        else
        {
            if( count($mergeArray) < 1 )
                return null;

            $xml = '<vsys>';
            foreach($mergeArray as $subSystemName => &$types)
            {
                $xml .= "<entry name=\"{$subSystemName}\"><rules>";

                foreach($types as $typeName => &$rules)
                {
                    $xml .= "<{$typeName}><rules>\n";

                    foreach($rules as $ruleName => $xmlValue )
                    {
                        $xml .= "<entry name=\"{$ruleName}\">{$xmlValue}</entry>\n";
                    }

                    $xml .= "</rules></{$typeName}>\n";
                }

                $xml .= "</rules></entry>";
            }
            $xml .= '</vsys>';

            return $xml;
        }
    }

    public function doBundled_API_Call()
    {
        $setString = $this->generateRuleMergedApuChangeString(true);
        if( $setString !== null )
        {
            print $this->padding . ' - sending API call for SHARED... ';
            $this->connector->sendSetRequest('/config/shared', $setString);
            print "OK!\n";
        }
        $setString = $this->generateRuleMergedApuChangeString(false);
        if( $setString !== null )
        {
            print $this->padding . ' - sending API call for Device-Groups/VSYS... ';
            $this->connector->sendSetRequest("/config/devices/entry[@name='localhost.localdomain']", $setString);
            print "OK!\n";
        }
    }

    private function enclose($value, $nowrap = true)
    {
        $output = '';

        if( is_string($value) )
            $output = htmlspecialchars($value);
        elseif( is_array($value) )
        {
            $output = '';
            $first = true;
            foreach( $value as $subValue )
            {
                if( !$first )
                {
                    $output .= '<br />';
                }
                else
                    $first= false;

                if( is_string($subValue) )
                    $output .= htmlspecialchars($subValue);
                else
                    $output .= htmlspecialchars($subValue->name());
            }
        }
        else
            derr('unsupported');

        if( $nowrap )
            return '<td style="white-space: nowrap">'.$output.'</td>';

        return "<td>{$output}</td>";
    }

    /**
     * @param Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $rule
     * @param $fieldName
     * @return string
     */
    public function ruleFieldHtmlExport($rule, $fieldName, $wrap = true)
    {
        if( $fieldName == 'location' )
        {
            if ($rule->owner->owner->isPanorama() || $rule->owner->owner->isFirewall())
                return self::enclose('shared');
            return self::enclose($rule->owner->owner->name(), $wrap);
        }

        if( $fieldName == 'name')
            return self::enclose($rule->name(), $wrap);

        if( $fieldName == 'description' )
            return self::enclose($rule->description(), $wrap);

        if( $fieldName == 'tags' )
            return self::enclose( $rule->tags->getAll(), $wrap );

        if( $fieldName == 'type' )
            return self::enclose( $rule->ruleNature(), $wrap );

        if( $fieldName == 'from' )
        {
            if( $rule->from->isAny() )
                return self::enclose('any');
            return self::enclose($rule->from->getAll(), $wrap);
        }

        if( $fieldName == 'to' )
        {
            if( $rule->to->isAny() )
                return self::enclose('any');
            return self::enclose($rule->to->getAll(), $wrap);
        }

        if( $fieldName == 'source' )
        {
            if( $rule->source->isAny() )
                return self::enclose('any');
            return self::enclose($rule->source->getAll(), $wrap);
        }

        if( $fieldName == 'destination' )
        {
            if( $rule->destination->isAny() )
                return self::enclose('any');
            return self::enclose($rule->destination->getAll(), $wrap);
        }

        if( $fieldName == 'service' )
        {
            if( $rule->isDecryptionRule() )
                return self::enclose('');
            if( $rule->isAppOverrideRule() )
                return self::enclose($rule->ports());
            if( $rule->isNatRule() )
            {
                if( $rule->service !== null )
                    return self::enclose(Array($rule->service));
                return self::enclose('any');
            }
            if( $rule->services->isAny() )
                return self::enclose('any');
            return self::enclose($rule->services->getAll(), $wrap);
        }

        if( $fieldName == 'action' )
        {
            if( !$rule->isSecurityRule() && !$rule->isCaptivePortalRule() )
                return self::enclose('');

            return self::enclose(Array($rule->action()));
        }

        if( $fieldName == 'log_start' )
        {
            if( !$rule->isSecurityRule() )
                return self::enclose('');
            return self::enclose(boolYesNo($rule->logStart()), $wrap);
        }
        if( $fieldName == 'log_end')
        {
            if( !$rule->isSecurityRule() )
                return self::enclose('');
            return self::enclose(boolYesNo($rule->logEnd()), $wrap);
        }

        if( $fieldName == 'snat_type' )
        {
            if( !$rule->isNatRule() )
                return self::enclose('');
            return self::enclose($rule->natType(), $wrap);
        }
        if( $fieldName == 'snat_trans' )
        {
            if( !$rule->isNatRule() )
                return self::enclose('');
            return self::enclose($rule->snathosts->getAll(), $wrap);
        }
        if( $fieldName == 'dnat_host' )
        {
            if( !$rule->isNatRule() )
                return self::enclose('');
            if( $rule->dnathost === null )
                return self::enclose('');
            return self::enclose(Array($rule->dnathost), $wrap);
        }

        if( $fieldName == 'disabled' )
        {
            return self::enclose( boolYesNo($rule->isDisabled()) );
        }

        if( $fieldName == 'users' )
        {
            if( $rule->isNatRule() )
                return self::enclose('');

            if( !$rule->userID_IsCustom() )
                return self::enclose($rule->userID_type(), $wrap);
            if( $rule->userID_IsAny() )
                return self::enclose('any');
            return self::enclose($rule->userID_getUsers(), $wrap);
        }

        return self::enclose('unsupported');

    }
}

require_once "actions-rule.php";


class ServiceCallContext extends CallContext
{
    /** @var  Service|ServiceGroup */
    public $object;
}

class AddressCallContext extends CallContext
{
    /** @var  Address|AddressGroup */
    public $object;
}


