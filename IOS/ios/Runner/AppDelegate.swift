import Flutter
import UIKit
import CoreLocation
import UserNotifications
import Security

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterStreamHandler, CLLocationManagerDelegate {
    private var methodChannel: FlutterMethodChannel?
    private var eventChannel: FlutterEventChannel?
    private var eventSink: FlutterEventSink?

    private let locationManager = CLLocationManager()
    private var isTracking = false
    private var lastFixTimeMillis: Int64 = 0
    private var lastUploadTimeMillis: Int64 = 0

    override func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
    ) -> Bool {
        let controller: FlutterViewController = window?.rootViewController as! FlutterViewController

        methodChannel = FlutterMethodChannel(
            name: "com.sst.attendance/native",
            binaryMessenger: controller.binaryMessenger
        )

        eventChannel = FlutterEventChannel(
            name: "com.sst.attendance/events",
            binaryMessenger: controller.binaryMessenger
        )
        eventChannel?.setStreamHandler(self)

        locationManager.delegate = self
        locationManager.desiredAccuracy = kCLLocationAccuracyBest
        locationManager.distanceFilter = 10.0 // meters

        methodChannel?.setMethodCallHandler { [weak self] (call: FlutterMethodCall, result: @escaping FlutterResult) in
            self?.handleMethodCall(call: call, result: result)
        }

        GeneratedPluginRegistrant.register(with: self)
        return super.application(application, didFinishLaunchingWithOptions: launchOptions)
    }

    private func handleMethodCall(call: FlutterMethodCall, result: @escaping FlutterResult) {
        switch call.method {
        case "deviceUid":
            result(getOrCreateDeviceUid())

        case "deviceInfo":
            result([
                "model": UIDevice.current.model,
                "brand": "Apple",
                "os_version": UIDevice.current.systemVersion,
                "platform": "ios"
            ])

        case "defaultApiBase":
            // Can be configured at build time or fallback to nil
            let apiBase = Bundle.main.object(forInfoDictionaryKey: "API_BASE_URL") as? String
            result(apiBase)

        case "setBaseUrl":
            if let args = call.arguments as? [String: Any], let url = args["url"] as? String {
                UserDefaults.standard.set(url, forKey: "sst_api_base_url")
            }
            result(nil)

        case "setSession":
            if let args = call.arguments as? [String: Any] {
                if let token = args["token"] as? String {
                    UserDefaults.standard.set(token, forKey: "sst_session_token")
                }
                if let config = args["config"] as? String {
                    UserDefaults.standard.set(config, forKey: "sst_session_config")
                }
            }
            result(nil)

        case "clearSession":
            UserDefaults.standard.removeObject(forKey: "sst_session_token")
            UserDefaults.standard.removeObject(forKey: "sst_session_config")
            result(nil)

        case "startTracking":
            startLocationTracking()
            result(nil)

        case "stopTracking":
            stopLocationTracking()
            result(nil)

        case "trackingStatus":
            result([
                "running": isTracking,
                "wanted": isTracking,
                "pending": 0,
                "last_fix_at": lastFixTimeMillis,
                "last_upload_at": lastUploadTimeMillis,
                "interval_min": 10
            ])

        case "syncNow":
            result(nil)

        case "integrityCheck":
            result([
                "ok": true,
                "reason": nil
            ])

        case "setShiftOpen":
            result(nil)

        case "setOutsideReasonGiven":
            result(nil)

        case "applyScreenCapturePolicy":
            result(false)

        case "isDeviceAdminActive":
            // Device Admin is Android-only; iOS equivalent is Supervised MDM
            result(false)

        case "requestDeviceAdmin":
            result(nil)

        case "releaseDeviceAdmin":
            result(true)

        case "canInstallPackages":
            // iOS disallows sideloading packages programmatically
            result(false)

        case "requestInstallPermission":
            result(nil)

        case "installApk":
            result(false)

        case "isBatteryOptimised":
            // iOS manages power automatically
            result(false)

        case "requestBatteryExemption":
            result(nil)

        case "openAppSettings", "openLocationSettings", "openNotificationSettings":
            if let url = URL(string: UIApplication.openSettingsURLString) {
                UIApplication.shared.open(url, options: [:], completionHandler: nil)
            }
            result(nil)

        case "notificationsEnabled":
            UNUserNotificationCenter.current().getNotificationSettings { settings in
                DispatchQueue.main.async {
                    result(settings.authorizationStatus == .authorized)
                }
            }

        default:
            result(FlutterMethodNotImplemented)
        }
    }

    // MARK: - Location Management
    private func startLocationTracking() {
        isTracking = true
        if #available(iOS 14.0, *) {
            locationManager.requestAlwaysAuthorization()
        }
        locationManager.allowsBackgroundLocationUpdates = true
        locationManager.pausesLocationUpdatesAutomatically = false
        locationManager.startUpdatingLocation()
    }

    private func stopLocationTracking() {
        isTracking = false
        locationManager.stopUpdatingLocation()
    }

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let loc = locations.last else { return }
        lastFixTimeMillis = Int64(loc.timestamp.timeIntervalSince1970 * 1000)
    }

    // MARK: - FlutterStreamHandler
    func onListen(withArguments arguments: Any?, eventSink events: @escaping FlutterEventSink) -> FlutterError? {
        self.eventSink = events
        return nil
    }

    func onCancel(withArguments arguments: Any?) -> FlutterError? {
        self.eventSink = nil
        return nil
    }

    // MARK: - Keychain-backed Persistent Device Identifier
    private func getOrCreateDeviceUid() -> String {
        let key = "com.sst.attendance.device_uid"
        if let existing = keychainRead(key: key) {
            return existing
        }
        let newUid = UIDevice.current.identifierForVendor?.uuidString ?? UUID().uuidString
        keychainSave(key: key, value: newUid)
        return newUid
    }

    private func keychainSave(key: String, value: String) {
        guard let data = value.data(using: .utf8) else { return }
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: key,
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlock
        ]
        SecItemDelete(query as CFDictionary)
        SecItemAdd(query as CFDictionary, nil)
    }

    private func keychainRead(key: String) -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var result: AnyObject?
        if SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
           let data = result as? Data,
           let str = String(data: data, encoding: .utf8) {
            return str
        }
        return nil
    }
}
