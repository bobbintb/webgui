<?PHP
/* Copyright 2005-2022, Lime Technology
 * Copyright 2012-2022, Bergware International.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?
$docroot  = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
// add translations
$_SERVER['REQUEST_URI'] = 'settings';
// special case when script is called on form-submit and processed by update.php
if (!isset($_SESSION['locale'])) $_SESSION['locale'] = $_POST['#locale'];
require_once "$docroot/webGui/include/Translations.php";
require_once "$docroot/webGui/include/Helpers.php";

$dockerd   = is_file('/var/run/dockerd.pid') && is_dir('/proc/'.file_get_contents('/var/run/dockerd.pid'));
$etc       = '/etc/wireguard';
$validIP4  = "(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)){3}";
$validIP6  = "(([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|(:|([0-9a-fA-F]{1,4}:)+):(([0-9a-fA-F]{1,4}:)*[0-9a-fA-F]{1,4})?)";
$normalize = ['address'=>'Address', 'dns'=>'DNS', 'privatekey'=>'PrivateKey', 'publickey'=>'PublicKey', 'allowedips'=>'AllowedIPs', 'endpoint'=>'Endpoint'];
$dockernet = "172.31";

$t1 = '10'; // 10 sec timeout
$t2 = '15'; // 15 sec timeout

function mask2cidr($mask) {
  $long = ip2long($mask);
  $base = ip2long('255.255.255.255');
  return 32-log(($long ^ $base)+1,2);
}
function thisnet($ethX='eth0') {
  extract(parse_ini_file('state/network.ini',true));
  $net = long2ip(ip2long($$ethX['IPADDR:0']) & ip2long($$ethX['NETMASK:0'])).'/'.mask2cidr($$ethX['NETMASK:0']);
  return [$net,$$ethX['GATEWAY:0']];
}
function ipv4($ip) {
  return strpos($ip,'.')!==false;
}
function ipv6($ip) {
  return strpos($ip,':')!==false;
}
function ipset($ip) {
  return ipv4($ip) ? $ip : "[$ip]";
}
function ipsplit($ip) {
  return ipv4($ip) ? ':' : ']:';
}
function ipfilter(&$list) {
  // we only import IPv4 addresses at this moment, strip any IPv6 addresses
  $list = implode(', ',array_filter(array_map('trim',explode(',',$list)),'ipv4'));
}
function host($ip) {
  return strpos($ip,'/')!==false ? $ip : (ipv4($ip) ? "$ip/32" : "$ip/128");
}
function noNet($network) {
  return empty(exec("ip rule|grep -Pom1 'from $network'"));
}
function wgState($vtun,$state,$type=0) {
  global $t1,$etc;
  $tmp = '/tmp/wg-quick.tmp';
  exec("timeout $t1 wg-quick $state $vtun 2>$tmp");
  if ($type==8) {
    // make VPN tunneled access for Docker containers only
    $table = exec("grep -Pom1 'fwmark \K[\d]+' $tmp");
    $route = exec("grep -Pom1 '^Address=\K.+$' $etc/$vtun.conf");
    sleep(1);
    exec("ip -4 route flush table $table");
    exec("ip -4 route add $route dev $vtun table $table");
  }
  delete_file($tmp);
}
function status($vtun) {
  return in_array($vtun,explode(" ",exec("wg show interfaces")));
}
function vtun() {
  global $etc;
  $x = 0; while (file_exists("$etc/wg{$x}.conf")) $x++;
  return "wg{$x}";
}
function normalize(&$id) {
  // ensure correct capitalization of keywords, some VPN providers use the wrong case
  global $normalize;
  $id = $normalize[strtolower($id)];
}
function dockerNet($vtun) {
  return empty(exec("docker network ls --filter name='$vtun' --format='{{.Name}}'"));
}
function addDocker($vtun) {
  global $dockerd,$dockernet;
  $error = false;
  if ($dockerd && dockerNet($vtun)) {
    $index = substr($vtun,2)+200;
    $network = "$dockernet.$index.0/24";
    exec("docker network create $vtun --subnet=$network 2>/dev/null");
    $error = dockerNet($vtun);
    if (!$error && noNet($network)) {
      [$thisnet,$gateway] = thisnet();
      exec("ip -4 rule add from $network table $index");
      exec("ip -4 route add unreachable default table $index");
      exec("ip -4 route add $thisnet via $gateway table $index");
    }
  }
  return $error;
}
function delDocker($vtun) {
  global $dockerd,$dockernet;
  $error = false;
  if ($dockerd && !dockerNet($vtun)) {
    $index = substr($vtun,2)+200;
    $network = "$dockernet.$index.0/24";
    exec("docker network rm $vtun 2>/dev/null");
    $error = !dockerNet($vtun);
    if (!$error && exec("ip rule|grep -Pom1 'from $network'")) {
      exec("ip -4 route flush table $index");
      exec("ip -4 rule del from $network table $index");
    }
  }
  return $error;
}
function delPeer($vtun,$id='') {
  global $etc,$name;
  $dir = "$etc/peers";
  foreach (glob("$dir/peer-$name-$vtun-$id*",GLOB_NOSORT) as $peer) delete_file($peer);
}
function addPeer(&$x) {
  global $peers,$var;
  $peers[$x] = ['[Interface]'];                                  // [Interface]
  if ($var['client']) $peers[$x][] = $var['client'];             // #name
  if ($var['privateKey']) $peers[$x][] = $var['privateKey'];     // PrivateKey
  $peers[$x][] = $var['address'];                                // Address
  if ($var['listenport']) $peers[$x][] = $var['listenport'];     // ListenPort
  if ($var['dns']) $peers[$x][] = $var['dns'];                   // DNS server
  if ($var['mtu']) $peers[$x][] = $var['mtu'];                   // MTU
  $peers[$x][] = '';
  $peers[$x][] = "[Peer]";                                       // [Peer]
  if ($var['server']) $peers[$x][] = $var['server'];             // #name
  if ($var['handshake']) $peers[$x][] = $var['handshake'];       // PersistentKeepalive
  if ($var['presharedKey']) $peers[$x][] = $var['presharedKey']; // PresharedKey
  $peers[$x][] = $var['publicKey'];                              // PublicKey
  if ($var['tunnel']) $peers[$x][] = $var['tunnel'];             // Tunnel address
  $peers[$x][] = $var['endpoint'] ?: $var['internet'];           // Endpoint
  $peers[$x][] = $var['allowedIPs'];                             // AllowedIPs
  $x++;
}
function autostart($vtun,$cmd) {
  global $etc;
  $autostart = "$etc/autostart";
  $list = file_exists($autostart) ? array_filter(explode(' ',file_get_contents($autostart))) : [];
  $key = array_search($vtun,$list);
  switch ($cmd) {
    case 'off': if ($key!==false) unset($list[$key]); break;
    case 'on' : if ($key===false) $list[] = $vtun; break;
  }
  if (count($list)) file_put_contents($autostart,implode(' ',$list)); else delete_file($autostart);
}
function createPeerFiles($vtun) {
  global $etc,$peers,$name,$gone,$vpn;
  $dir = "$etc/peers";
  $tmp = "/tmp/list.tmp";
  if (is_dir($dir)) {
    if (count($gone)) {
      foreach ($gone as $peer) {
        // one or more peers are removed, delete the associated files
        [$n,$i] = my_explode('-',$peer);
        delPeer($n,$i);
      }
      $new = 1;
      $peer = "$dir/peer-$name-$vtun";
      $files = glob("$peer-*.conf",GLOB_NOSORT);
      natsort($files);
      foreach ($files as $file) {
        $id = explode('-',basename($file,'.conf'))[3];
        if ($id > $new) {
          // rename files to match revised peers list
          rename($file,"$peer-$new.conf");
          rename(str_replace('.conf','.png',$file),"$peer-$new.png");
        }
        $new++;
      }
    }
  } else {
    mkdir($dir);
  }
  $list = [];
  foreach ($peers as $id => $peer) {
    if (empty($peer[1])) break; // tunnel without any peers
    $cfg    = "$dir/peer-$name-$vtun-$id.conf";
    $cfgold = @file_get_contents($cfg) ?: '';
    $cfgnew = implode("\n",$peer)."\n";
    if ($cfgnew !== $cfgold && $vpn==0) {
      $list[] = "$vtun: peer $id (".($peer[1][0]=='#' ? substr($peer[1],1) : _('no name')).')';
      file_put_contents($cfg,$cfgnew);
      $png = str_replace('.conf','.png',$cfg);
      exec("qrencode -t PNG -r $cfg -o $png");
    }
  }
  // store the peer names which are updated
  if (count($list)) file_put_contents($tmp,implode("<br>",$list)); else delete_file($tmp);
}
function createList($list) {
  return implode(', ',array_unique(array_filter(array_map('trim',explode(',',$list)))));
}
function createIPs($list) {
  return implode(', ',array_map('host',array_filter(array_map('trim',explode(',',$list)))));
}
function parseInput($vtun,&$input,&$x) {
  global $conf,$user,$var,$default,$default6,$vpn,$dockernet;
  $section = 0; $addPeer = false;
  foreach ($input as $key => $value) {
    if ($key[0]=='#') continue;
    [$id,$i] = my_explode(':',$key);
    if ($i != $section) {
      if ($section==0) {
        // add WG routing for docker containers. Only IPv4 supported
        $index   = substr($vtun,2)+200;
        $network = "$dockernet.$index.0/24";
        [$thisnet,$gateway] = thisnet();
        $conf[]  = "PostUp=ip -4 route flush table $index";
        $conf[]  = "PostUp=ip -4 route add default via $tunip table $index";
        $conf[]  = "PostUp=ip -4 route add $thisnet via $gateway table $index";
        $conf[]  = "PostDown=ip -4 route flush table $index";
        $conf[]  = "PostDown=ip -4 route add unreachable default table $index";
        $conf[]  = "PostDown=ip -4 route add $thisnet via $gateway table $index";
      }
      $conf[] = "\n[Peer]";
      // add peers, this is only used for peer sections
      $addPeer ? addPeer($x) : $addPeer = true;
      $section = $i;
    }
    switch ($id) {
    case 'Name':
      if ($value) $conf[] = "#$value";
      if ($i==0) {
        $var['server'] = $value ? "#$value" : false;
      } else {
        $var['client'] = $value ? "#$value" : false;
      }
      break;
    case 'PrivateKey':
      if ($i==0) {
        $conf[] = "$id=$value";
      } else {
        if ($value) $user[] = "$id:$x=\"$value\"";
        $var['privateKey'] = $value ? "$id=$value" : false;
      }
      break;
    case 'PublicKey':
      if ($i==0) {
        $user[] = "$id:0=\"$value\"";
        $var['publicKey'] = "$id=$value";
      } else {
        $conf[] = "$id=$value";
      }
      break;
    case 'DNS':
      if ($i > 0 && $value) {
        $user[] = "$id:$x=\"$value\"";
        $var['dns'] = "$id=$value";
      } else $var['dns'] = false;
      break;
    case 'PROT':
      $protocol = $value;
      $user[] = "$id:0=\"$value\"";
      switch ($protocol) {
        case '46': $var['default']  = "AllowedIPs=$default, $default6"; break;
        case '6' : $var['default']  = "AllowedIPs=$default6"; break;
        default  : $var['default']  = "AllowedIPs=$default"; break;
      }
      break;
    case 'TYPE':
      $list = $value<4 ? ($value%2==1 ? $var['subnets1'] : $var['subnets2']) : ($value<6 ? ($value%2==1 ? $var['shared1'] : $var['shared2']) : $var['default']);
      $var['allowedIPs'] = createIPs($list);
      $var['tunnel'] = ($value==2||$value==3) ? $tunnel : false;
      $user[] = "$id:$x=\"$value\"";
      if ($value>=7) $vpn = $value;
      break;
    case 'Network6': if (!$protocol) break;
    case 'Network':
    case 'UPNP':
    case 'DROP':
    case 'RULE':
    case 'NAT':
      $user[] = "$id:0=\"$value\"";
      break;
    case 'Address':
      $hosts = createIPs($value);
      if ($i==0) {
        $conf[] = "$id=$value";
        $tunnel = "$id=$hosts";
        $tunip  = $value;
      } else {
        $user[] = "$id:$x=\"$value\"";
        $var['address'] = "$id=$hosts";
      }
      break;
    case 'MTU':
      if ($value) $conf[] = "$id=$value";
      $var['mtu'] = $value ? "$id=$value" : false;
      break;
    case 'Endpoint':
      if ($i==0) {
        $user[] = "$id:0=\"$value\"";
        $var['endpoint'] = $value ? "Endpoint=".ipset($value) : false;
      } else {
        if ($value) $conf[] = "$id=$value";
        $var['listenport'] = $value ? "ListenPort=".explode(ipsplit($value),$value)[1] : false;
        if ($var['endpoint'] && strpos($var['endpoint'],ipsplit($var['endpoint']))===false) $var['endpoint'] .= ":".explode(ipsplit($var['internet']),$var['internet'])[1];
      }
      break;
    case 'PersistentKeepalive':
      if ($value) $conf[] = "$id=$value";
      $var['handshake'] = $value ? "$id=$value" : false;
      break;
    case 'PresharedKey':
      if ($value) $conf[] = "$id=$value";
      $var['presharedKey'] = $value ? "$id=$value" : false;
      break;
    case 'AllowedIPs':
      $conf[] = "$id=".createList($value);
      break;
    default:
      if ($value) $conf[] = "$id=$value";
      break;
    }
  }
}
$default = '0.0.0.0/0';
$default6 = '::/0';
switch ($_POST['#cmd']) {
case 'keypair':
  $private = exec("wg genkey");
  $public = exec("wg pubkey <<<'$private'");
  echo $private."\0".$public;
  break;
case 'presharedkey':
  echo exec("wg genpsk");
  break;
case 'update':
  if (!exec("iptables -S|grep -om1 'WIREGUARD$'")) {
    exec("iptables -N WIREGUARD;iptables -A FORWARD -j WIREGUARD");
  }
  if (!exec("ip6tables -S|grep -om1 'WIREGUARD$'")) {
    exec("ip6tables -N WIREGUARD;ip6tables -A FORWARD -j WIREGUARD");
  }
  $cfg  = $_POST['#cfg'];
  $wg   = $_POST['#wg'];
  $name = $_POST['#name'];
  $vtun = $_POST['#vtun'];
  $gone = explode(',',$_POST['#deleted']);
  $conf = ['[Interface]'];
  $user = $peers = $var = [];
  $var['subnets1'] = "AllowedIPs=".createList($_POST['#subnets1']);
  $var['subnets2'] = "AllowedIPs=".createList($_POST['#subnets2']);
  $var['shared1']  = "AllowedIPs=".createList($_POST['#shared1']);
  $var['shared2']  = "AllowedIPs=".createList($_POST['#shared2']);
  $var['internet'] = "Endpoint=".createList($_POST['#internet']);
  $x = 1; $vpn = 0;
  parseInput($vtun,$_POST,$x);
  addPeer($x);
  addDocker($vtun);
  $upstate = status($vtun);
  wgState($vtun,'down');
  file_put_contents($file,implode("\n",$conf)."\n");
  file_put_contents($cfg,implode("\n",$user)."\n");
  createPeerFiles($vtun);
  if ($upstate) wgState($vtun,'up',$_POST['#type']);
  $save = false;
  break;
case 'toggle':
  $vtun = $_POST['#vtun'];
  switch ($_POST['#wg']) {
  case 'stop':
    wgState($vtun,'down');
    echo status($vtun) ? 1 : 0;
    break;
  case 'start':
    $index = substr($vtun,2)+200;
    $network = "$dockernet.$index.0/24";
    if (noNet($network)) {
      exec("ip -4 rule add from $network table $index");
      exec("ip -4 route add unreachable default table $index");
    }
    wgState($vtun,'up',$_POST['#type']);
    echo status($vtun) ? 0 : 1;
    break;
  }
  break;
case 'ping':
  $addr = $_POST['#addr'];
  echo exec("ping -qc1 -W4 $addr|grep -Pom1 '1 received'");
  break;
case 'public':
  $ip = $_POST['#ip'];
  $v4 = $_POST['#prot']!='6';
  $v6 = $_POST['#prot']!='';
  $context = stream_context_create(['https'=>['timeout'=>12]]);
  $int_ipv4 = $v4 ? (preg_match("/^$validIP4$/",$ip) ? $ip : (@dns_get_record($ip,DNS_A)[0]['ip'] ?: '')) : '';
  $ext_ipv4 = $v4 ? (@file_get_contents('https://wanip4.unraid.net',false,$context) ?: '') : '';
  $int_ipv6 = $v6 ? (preg_match("/^$validIP6$/",$ip) ? $ip : (@dns_get_record($ip,DNS_AAAA)[0]['ipv6'] ?: '')) : '';
  $ext_ipv6 = $v6 ? (@file_get_contents('https://wanip6.unraid.net',false,$context) ?: '') : '';
  echo "$int_ipv4;$ext_ipv4;$int_ipv6;$ext_ipv6";
  break;
case 'addtunnel':
  $vtun = vtun();
  $name = $_POST['#name'];
  touch("$etc/$vtun.conf");
  wgState($vtun,'down');
  delete_file("$etc/$vtun.cfg");
  delPeer($vtun);
  autostart($vtun,'off');
  break;
case 'deltunnel':
  $vtun = $_POST['#vtun'];
  $name = $_POST['#name'];
  $error = delDocker($vtun);
  if (!$error) {
    wgState($vtun,'down');
    delete_file("$etc/$vtun.conf","$etc/$vtun.cfg");
    delPeer($vtun);
    autostart($vtun,'off');
  }
  echo $error ? 1 : 0;
  break;
case 'import':
  $name = $_POST['#name'];
  $user = $peers = $var = $import = $sort = [];
  $entries = array_filter(array_map('trim',preg_split('/\[(Interface|Peer)\]/',$_POST['#data'])));
  foreach($entries as $key => $entry) {
    $i = $key-1;
    foreach (explode("\n",$entry) as $row) {
      if (ltrim($row)[0]!='#') {
        [$id,$data] = array_map('trim',my_explode('=',$row));
        normalize($id);
        $import["$id:$i"] = $data;
      } elseif ($i >= 0) {
        $import["Name:$i"] = substr(trim($row),1);
      }
    }
  }
  if ($import['PrivateKey:0'] && !$import['PublicKey:0']) $import['PublicKey:0'] = exec("wg pubkey <<<'{$import['PrivateKey:0']}'");
  $import['UPNP:0'] = 'no';
  $import['NAT:0'] = 'no';
  [$subnet,$mask] = my_explode('/',$import['Address:0']);
  if (ipv4($subnet)) {
    $mask = ($mask > 0 && $mask < 32) ? $mask : 24;
    $import['Network:0'] = long2ip(ip2long($subnet) & (0x100000000-2**(32-$mask))).'/'.$mask;
    $import['Address:0'] = $subnet;
    $import['PROT:0'] = '';
  } else {
    $mask = ($mask > 0 && $mask < 128) ? $mask : 64;
    $import['Network6:0'] = strstr($subnet,'::',true).'::/'.$mask;
    $import['Address:0'] = $subnet;
    $import['PROT:0'] = '6';
  }
  $import['Endpoint:0'] = '';
  for ($n = 1; $n <= $i; $n++) {
    $vpn = array_map('trim',explode(',',$import["AllowedIPs:$n"]));
    $vpn = (in_array($default,$vpn) || in_array($default6,$vpn)) ? 8 : 0;
    if ($vpn==8) $import["Address:$n"] = '';
    $import["TYPE:$n"] = $vpn;
    ipfilter($import["AllowedIPs:$n"]);
    if ($import["TYPE:$n"]==0) $var['subnets1'] = "AllowedIPs=".$import["AllowedIPs:$n"];
  }
  foreach ($import as $key => $val) $sort[] = explode(':',$key)[1];
  array_multisort($sort,$import);
  $x = 1;
  $conf = ['[Interface]'];
  $var['default'] = $import['PROT:0']=='' ? "AllowedIPs=$default" : "AllowedIPs=$default6";
  $var['internet'] = "Endpoint=unknown";
  $vtun = vtun();
  parseInput($vtun,$import,$x);
  addPeer($x);
  file_put_contents("$etc/$vtun.conf",implode("\n",$conf)."\n");
  file_put_contents("$etc/$vtun.cfg",implode("\n",$user)."\n");
  delPeer($vtun);
  addDocker($vtun);
  autostart($vtun,'off');
  echo $vtun;
  break;
case 'autostart':
  autostart($_POST['#vtun'],$_POST['#start']);
  break;
case 'upnp':
  $upnp = '/var/tmp/upnp';
  if (is_executable('/usr/bin/upnpc')) {
    $gw = $_POST['#gw'].':';
    $link = $_POST['#link'];
    $xml = @file_get_contents($upnp) ?: '';
    if ($xml) {
      exec("timeout $t1 stdbuf -o0 upnpc -u $xml -m $link -l 2>&1|grep -qm1 'refused'",$output,$code);
      if ($code!=1) $xml = '';
    }
    if (!$xml) {
      exec("timeout $t2 stdbuf -o0 upnpc -m $link -l 2>/dev/null|grep -Po 'desc: \K.+'",$desc);
      foreach ($desc as $url) if ($url && strpos($url,$gw)!==false) {$xml = $url; break;}
    }
  } else $xml = "";
  file_put_contents($upnp,$xml);
  echo $xml;
  break;
case 'upnpc':
  if (!is_executable('/usr/bin/upnpc')) break;
  $xml = $_POST['#xml'];
  $vtun = $_POST['#vtun'];
  $link = $_POST['#link'];
  $ip = $_POST['#ip'];
  if ($_POST['#wg']=='active') {
    exec("timeout $t1 stdbuf -o0 upnpc -u $xml -m $link -l 2>/dev/null|grep -Po \"^(ExternalIPAddress = \K.+|.+\KUDP.+>$ip:[0-9]+ 'WireGuard-$vtun')\"",$upnp);
    [$addr,$upnp] = $upnp;
    [$type,$rule] = my_explode(' ',$upnp);
    echo $rule ? "UPnP: $addr:$rule/$type" : _("UPnP: forwarding not set");
  } else {
    echo _("UPnP: tunnel is inactive");
  }
  break;
}
?>
