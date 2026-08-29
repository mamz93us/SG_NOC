# SG_NOC Modules

> Full per-subsystem file inventory (50 modules — confirmed against `app/Http/Controllers/Admin` [152 files], `app/Services` [105], `app/Console/Commands` [75], `app/Models` [204]). Not exhaustive per module, but every distinct subsystem is represented. For request flow see `docs/API_MAP.md`; for design patterns see `docs/ARCHITECTURE.md`; for cross-module data movement see `docs/DATA_FLOW.md`.
>
> Paths below are relative to `app/`. `Admin\` = `Http/Controllers/Admin/`, `Portal\` = `Http/Controllers/Portal/`, `Public\` = `Http/Controllers/Public/`, `Api\` = `Http/Controllers/Api/`.

## Fleet & assets

**1. ITAM** (devices, accessories, licenses, POs, warranty, suppliers)
Admin\{Device,DeviceImport,DeviceMetrics,DeviceModel,Accessory,AssetScrap,AssetTransfer,AssetType,AssetReport,PurchaseOrder,WarrantyTracker,Supplier,License,LicenseMonitor}Controller · Services/{AssetCodeService,DepreciationService,DeviceLinkingService,LicenseMonitorService} · Models/{Device,DeviceModel,DeviceAccessLog,DeviceMac,Accessory,AccessoryAssignment,AssetHistory,AssetType,PurchaseOrder(Item),License,LicenseAssignment,LicenseMonitor,Supplier} · Commands/{LicensesRenameFromSku,SyncLicenseAssignments,AssetsBackfillEnrollmentDate}

**2. SNMP monitoring & metrics**
Admin\{SnmpDevice,SnmpMonitoring}Controller · Services/{SnmpMonitorService,Snmp/SnmpClient,Snmp/MibParser} · Models/{SnmpDevice,SnmpDiscoveredDevice,SnmpSensor,MonitoredHost,SensorMetric,SensorMetricHourly,SensorMetricDaily,Mib} · Commands/{CaptureAvailabilitySnapshots,ImportBundledMibs}

**3. Syslog / NOC alert engine & incidents**
Admin\{Syslog,Incident,AlertFeed,AlertRule,Noc,NocOverview}Controller · Services/{AsteriskSyslogParser,SophosSyslogParser,AlertEvaluator,NocAlertEngine,HealthScoringService} · Models/{SyslogMessage,SyslogAlertRule,NocEvent,NocMetricSnapshot,Incident,IncidentComment,AlertRule,AlertState,HostCheck,LinkCheck} · Api\GraylogWebhookController

**4. Branch health scoring**
Admin\BranchHealthIndexController · Services/BranchHealth/{BranchHealthConfig,BranchHealthEvaluator,BranchSlice,BranchTelemetryLoader}

**5. IPAM / DHCP / discovery / access points**
Admin\{Ipam,IpReservation,DhcpLease,AccessPoint,IpScanner,NetworkDiscovery}Controller · Services/{IpamService,DhcpLeaseService,AccessPointImporter,NetworkDiscoveryService,PingService} — `AccessPointImporter::ensureAsset()` is the single entry point linking APs into ITAM · Models/{IpamSubnet,IpReservation,DhcpLease,AccessPoint,DiscoveryScan,DiscoveryResult} · Commands/{DetectDhcpConflicts,RelabelDhcpNetworks,PingAccessPoints,SyncFortiGateDhcp}

**6. Switching & network infrastructure** (ports, QoS, drops, topology, port-map, CDP)
Admin\{SwitchDrop,SwitchQos,PortMap,Topology,Network}Controller · Services/{CiscoInterfaceParser,CiscoTelnetClient,MlsQosParser,Network/SwitchReconciler,Network/SnmpConfigExtractor,TopologyService,Network/WifiMacDirectory,Network/EmployeeNetworkLocator,PhonePortDetectionService} · Models/{NetworkSwitch,NetworkPort,NetworkRack,NetworkFloor,NetworkOffice,NetworkClient,NetworkEvent,NetworkSyncLog,SwitchCdpNeighbor,SwitchDropStat,SwitchInterfaceStat,SwitchQosStat,SwitchRunningConfig,PhonePortMap} · Commands/{ImportBranchSwitches,SwitchPollDrops,SwitchPollMlsQos,SwitchSnmpBringup,SwitchSnmpConfig,SwitchTelnetCreds}

**7. Meraki wireless**
Services/Network/MerakiService · Commands/SyncMeraki (no dedicated model/controller found — check `Network` controller/config for surfacing)

**8. Tunnel Health Watchdog**
Admin\{TunnelHealth,TunnelHealthHistory,TunnelOutageReport}Controller · Services/Network/TunnelWatchdog · Models/{BranchTunnel,TunnelProbe,TunnelHealthCheck,TunnelOutage} (legacy passive: VpnLog, VpnTunnel) · Commands/{WatchBranchTunnels,BackfillTunnelOutages}

**9. ISP / SLA**
Admin\{IspConnection,IspProvider,IspReport,Sla}Controller · Services/SlaMonitorService · Models/{IspConnection,IspProvider,IspProviderPackage} · Commands/ImportIspConnections

## Firewalls & access control

**10. Sophos (firewall + Central cloud + VPN)**
Admin\{SophosFirewall,SophosCentral,SophosVpnMonitor}Controller · Services/Sophos/{SophosApiService,SophosCentralService,SophosVpnBoard} · Models/{SophosFirewall,SophosFirewallRule,SophosInterface,SophosNetworkObject,SophosVpnTunnel,SophosCentralFirewall,SophosCentralAccessPoint} · Commands/{SyncSophosCommand,SyncSophosCentralCommand}

**11. FortiGate**
Admin\FortigateFirewallController · Services/FortiGate/FortiGateApiService · Models/FortigateFirewall

**12. RADIUS (NAC / MAC auth)**
Admin\{RadiusMacOverride,RadiusMacRegistry,RadiusNas,RadiusVlanPolicy}Controller · Services/RadiusVlanPolicyResolver · Models/{RadiusBranchVlanPolicy,RadiusMacOverride,RadiusNasClient} · Commands/SyncRadiusMacs

**13. Access Gateway (NOC-AGW)**
Admin\AccessGatewayController · Models/{AgwAllowlist,AgwAudit,AgwBlocklist,AgwIpHistory} · Commands/SyncAgwAllowlist · `noc-agw/` (separate FastAPI process — see `docs/CODEBASE_MAP.md`)

## Telephony

**14. UCM telephony (extensions, trunks, landlines)**
Admin\{UcmServer,Extension,Trunk,Landline}Controller · Services/IppbxApiService (JSON-over-HTTPS + MD5 challenge, not SOAP) · Models/{UcmExtensionCache,UcmTrunkCache,UcmActiveCall,UcmServer,Landline} · Commands/{ProbeUcmStorage,TestUcmPayload}

**15. Phone firmware server**
Admin\{PhoneFirmware,PhoneAutoAssign}Controller · Services/{Phone/FirmwarePublisher,PhoneDeviceLookup,PhoneInventoryService} · `PhonebookController` (records `SW` version from phone User-Agent — source of truth for the firmware board) · Models/{PhoneFirmware,PhoneFirmwareDownload,PhoneAccount,PhoneRequestLog} · Commands/{SyncPhoneFirmwareVersions,FetchRemoteFirmware,IngestFirmwareLog} · `deployment/firmware/` (nginx vhost, not app code — never behind HTTPS redirect)

**16. GDMS cloud phone management**
Admin\{Gdms,GdmsTemplate,PhoneManagement}Controller · Services/{GdmsService,GdmsBranchMapper} · Models/{GdmsTask,GdmsTemplate} · Commands/{SyncGdmsTemplates,SyncGdmsContacts,GdmsProbe}

**17. Voice Mesh (synthetic calls)**
Admin\VoiceMeshController, Api\VoiceMeshController (`/api/voice-mesh/config`, `/report`) · Services/Voice/{VoiceMeshMonitor,VoiceBranchResolver} · Models/{VoiceMeshNode,VoiceMeshPair,VoiceMeshResult,VoiceMeshRun} · Commands/CheckStaleVoiceMesh · `deployment/voice-mesh/` (Python/pjsua prober, not app code)

**18. Voice Quality / QoS monitoring**
Admin\VoiceQualityController · Models/{VoiceQualityReport,VqAlertEvent} · Commands/{VqCollectorDaemon,PruneVqData}

## Printers

**19. Printers / CUPS core**
Admin\{Printer,UnifiedPrinter,CupsPrinter,MyPrinters}Controller · Services/{CupsService,Printers/PrinterDiscoveryService} · Models/{Printer,CupsPrinter,CupsPrintJob} · Commands/{CupsRefreshStatus,PrintersLinkCupsCommand} · Public\PrinterSetupController

**20. Printer supplies, deployment & maintenance**
Admin\{PrinterUsageReport,PrinterDeploy,PrinterDriver,PrinterMaintenance,PrinterBranchSetting}Controller · Services/{PrinterScriptService,PrinterSnapshotBackfillService,PrinterSupplyMonitorService,Printers/PrinterTonerDigestService} · Models/{PrinterSupply,PrinterCounterSnapshot,PrinterDeployToken,PrinterDriver,PrinterMaintenanceLog,PrinterBranchSetting,PrinterAlertEmail,PrinterAlertRecipient} · Commands/{ImportBranchPrinters,PrintersBackfillSnapshotsCommand,SendTonerDigestCommand}

## Identity & HR

**21. Identity sync (Azure/Entra/Intune/AD)**
Admin\{Identity,AzureSync,IntuneGroup}Controller · Services/Identity/{IdentitySyncService,GraphService,AzureContactSyncService}, Services/AzureDeviceService · Models/{IdentityUser,IdentityGroup,IdentitySyncLog,AzureDevice,AzureBranchMapping,IntuneGroup,IntuneGroupMember,IntuneGroupPolicy} · Commands/{SyncIdentity,SyncAzureDevices,SyncIntuneNetData,SyncDeviceAccounts}

**22. Workflow engine**
Admin\{Workflow,WorkflowTemplate,WorkflowTrigger,Offboarding,ManagerFormPreview}Controller · Services/Workflow/{WorkflowEngine,WorkflowStepRegistry,OnboardingRequestService,OffboardingRequestService,OffboardingProcessor,OffboardingScheduler,EmployeeUpdateRequestService,UserProvisioningService,ExtensionProvisioningService,TicketingApiService} · Models/{WorkflowTemplate,WorkflowTemplateVersion,WorkflowRequest,WorkflowStep,WorkflowTask,WorkflowLog,OffboardingWorkflow,OffboardingToken,OnboardingManagerToken} · Commands/{AddRetryToWorkflow,RunOffboardingScheduler,RemindOnboardingManagers} · Public\{OnboardingFormController,OffboardingFormController}

**23. HR Portal / employee lifecycle / Oracle HR import**
Admin\{Employee,EmployeeItem,EmployeeAppAccount,OracleHrImport,HrApiKey}Controller · Portal\{HrOnboarding,HrOffboarding,HrEmployeeUpdate,HrWorkspace}Controller · Api\{HrOnboarding,HrOffboarding,HrEmployeeUpdate,HrGroupAssignment,HrLookup}Controller (`hr.api_key`-gated, see `docs/API_MAP.md`) · Services/Identity/OracleHrImportService · Models/{Employee,EmployeeAsset,EmployeeAppAccount,EmployeeItem,HrApiKey,HrImportBatch,HrImportRow} · Commands/{EmployeesSyncHrList,EmployeesPushAzure}

**24. Recruitment (Teamtailor)**
Admin\Teamtailor\{Candidate,Job}Controller · Services/Teamtailor/{TeamtailorApiService,TeamtailorCvExportService} · Models/TeamtailorCvExport · Commands/ProcessTeamtailorCvExports

**25. Training / course certificates**
Portal\Training\{Courses,CourseCertificates}Controller · Services/Training/CertificateUploadService · Models/Training/{Course,CourseCertificate} · public `/certificates/{token}` route (see `docs/API_MAP.md`)

## Access & portals

**26. Access analytics**
Http/Middleware/LogAccessVisit · Admin\AccessStatsController · Services/Access/AccessVisitRecorder · Models/AccessVisit

**27. Browser Portal / VPN egress**
Admin\BrowserPortal\{AdminBrowserPortalController,BrowserPortalSettingsController,BrowserSessionController}, Admin\WebBrowserController · Services/BrowserPortal/{SessionManager,DockerClient,NginxSnippetWriter} · Models/{BrowserPortalSettings,BrowserSession,BrowserSessionEvent} · `deployment/browser-portal/` (not app code)

**28. Employee cards / vCard + Apple Wallet**
`EmployeeCardController`, `VCardPortalController` (both directly under `Http/Controllers/`, not `Admin/`) · Services/EmployeeCard/{VCardService,WalletPassService} — legacy P12 needs OpenSSL legacy provider · public `/card/{token}` routes (see `docs/API_MAP.md`)

**29. Email signatures**
Admin\SignatureController · Services/Signature/SignatureRenderService · Models/{EmailSignatureTemplate,EmployeeSignatureRole,SignatureRequestLog} · Commands/{SignaturesFixImages,SignaturesHostLogos} · `deployment/signature/` (Intune deploy kit)

**30. IT Ticket Portal proxy**
Admin\TicketStatsController · Services/Ticketing/{BranchResolver,TicketVisitRecorder} · Models/TicketVisit · `config/ticket_tracking.php` (CIDR→branch map)

## Notifications & backups

**31. Notifications (routing, WhatsApp, email)**
Admin\{Notification,NotificationRule,WhatsappLog,EmailLog,MailSender}Controller · Services/{NotificationService (single funnel, `notifyViaRules()`), Notifications/WhatsAppService} · Models/{Notification,NotificationRule,NotificationSetting,WhatsappLog,EmailLog,MailSender} · Commands/ConfigureWhatsapp

**32. Backups (SFTPGo/AvePoint/DB/offboarding)**
Admin\{BackupAccount,AvePoint,AvepointBackupUpload,OffboardingBackupUpload}Controller · Services/{Backup/SftpgoApiService,DatabaseBackupService,AvePoint/AvePointApiService} · Models/{BackupAccount,SftpBackup,DatabaseBackup,AvepointBackup,AvepointDownloadAudit,OffboardingBackup,OffboardingDownloadAudit} · Commands/{SweepSftpBackupsToAzure,PruneSftpBackups,PruneDatabaseBackups,PruneOffboardingBackups,RunDatabaseBackup,CheckOverdueBackups} · Public\{AvepointDownloadController,OffboardingDownloadController}

**33. Branch Agent integration**
Admin\{BranchAgent,BranchLogCollector,BranchLog,Branch,BranchStore,BranchDepartmentGroup}Controller · Services/{BranchAgent/BranchDdnsService,BranchLogClient} · Models/{BranchAgent,BranchAgentWanIpHistory,BranchLogCollector,Branch,BranchDepartmentGroupMapping} · Commands/CheckStaleBranchAgents · `branch-agent/` (Go source, separate module)

**34. SMTP relay (Ricoh scan-to-email)**
Admin\SmtpRelayController · Services/SmtpConfigService · Models/{SmtpRelayMessage,SmtpRelayAttachment} · Commands/{IngestSmtpRelayLog,SmtpRelaySaslLine} · `deployment/smtp-relay/` (Postfix config, not app code)

## Email marketing

**35. Email marketing (campaigns/lists/subscribers/templates)**
Portal\EmailMarketing\{Campaigns,Subscribers,Lists,Segments,Tags,Dashboard,CampaignAnalytics,CampaignBenchmark,Templates,Fonts,Icons}Controller · Admin\EmailMarketing\{CampaignApprovals,EmailMarketingSettings,Quota,SenderIdentities,Suppressions}Controller · Services/EmailMarketing/{CampaignApprovalService,CampaignDispatcher,CsvSubscriberImporter,DynamicListSyncService,GeoIpLookup,MergeTagRenderer,SesService,SnsMessageVerifier,SpamWordChecker,SuppressionManager} · Models/EmailMarketing/{EmailCampaign,EmailCampaignSend,EmailEvent,EmailList,EmailMarketingFont,EmailMarketingIcon,EmailSegment,EmailSenderIdentity,EmailSubscriber,EmailSuppression,EmailTag,EmailTemplate} · Commands/EmailMarketing/{BackfillGeoIpCommand,DispatchScheduledCampaignsCommand,FixCorruptedMergeTagsCommand,ImportSubscribersCommand,PruneEmailEventsCommand,RecalcCampaignCountersCommand,SyncDynamicListsCommand} · Api\SnsEmailEventsController, Public\{UnsubscribeController,OptInConfirmController}

**36. World Cup contest**
Portal\EmailMarketing\WorldCupContestController · Services/WorldCup/ContestService · Commands/WorldCupFetchFlags

## DNS & certs

**37. DNS / subdomains / SSL certs**
Admin\{DnsAccount,DnsDomains,DnsLookup,DnsNameservers,DnsRecords,Subdomain,SslCertificate}Controller · Services/Dns/{AcmeService,CertificateExportService,GoDaddyService,SubdomainService,ZoneFileParser} · Models/{DnsAccount,SubdomainRecord,SslCertificate} · Commands/RenewExpiringCertsCommand

## System & admin

**38. Server status monitoring**
Admin\ServerStatusController · Services/ServerStatusService

**39. Credentials vault**
Admin\CredentialController · Models/{Credential,CredentialAccessLog}

**40. Device remote access (SSH/Proxy/Telnet)**
Admin\{DeviceSsh,DeviceProxy,Telnet}Controller · Models/DeviceSshSession · ties to `telnet-proxy/` side service (see `docs/CODEBASE_MAP.md`)

**41. IT task tracking (internal)**
Admin\ItTaskController · Models/{ItTask,ItTaskComment}

**42. Wallpaper deployment**
Admin\WallpaperController · Public\WallpaperDeploymentController (`/api/wallpapers/*`, see `docs/API_MAP.md`) · Models/{WallpaperCheckin,WallpaperSet}

**43. Forms builder**
Admin\FormBuilderController · Public\{PublicFormController} · Models/{FormSubmission,FormTemplate,FormToken}

**44. Documentation module**
Admin\DocumentationController

**45. Download Center**
Admin\DownloadCenterController · Public\DownloadShareController · Models/DownloadFile · Commands/{FetchDownloadCenterUrls,ImportLocalDownloadFile}

**46. Admin links / quick links**
Admin\{AdminLink,UserQuickLink}Controller · Models/{AdminLink,AdminLinkCategory,AdminLinkClick,UserFavoriteLink,UserQuickLink}

**47. Admin & access control** (users, permissions, settings, 2FA, dark mode, allowed/linked domains)
Admin\{User,UserPermission,Permissions,Settings,TwoFactor,DarkMode,AllowedDomain,LinkedAccount}Controller · Models/{User,UserPermission,RolePermission,Setting,AllowedDomain}

**48. Business apps / internet access levels / MAC directory**
Admin\{BusinessApp,InternetAccessLevel,MacAddress}Controller · Models/{BusinessApp,InternetAccessLevel,DeviceMac} · Commands/BusinessAppsGrant

**49. Sync status / workers dashboards**
Admin\{SyncStatus,WorkersDashboard}Controller · Models/{ServiceSyncLog,NetworkSyncLog}

**50. System email templates**
Admin\EmailTemplateController · Models/SystemEmailTemplate · Commands/EmailTemplateCheck · (distinct from EmailMarketing templates — this is the transactional-email override system, see `<!--f:name-->` markers)

---

Also present but not a distinct module: `Admin\ApiDocsController` (`/admin/api-docs`, self-documenting page over the routes in `docs/API_MAP.md`).
