import UIKit
import Flutter
import CoreLocation
import CoreMotion
import SQLite3
import Security
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate, CLLocationManagerDelegate {
    
    private var methodChannel: FlutterMethodChannel?
    private var eventChannel: FlutterEventChannel?
    private var eventSink: FlutterEventSink?
    
    private let locationManager = CLLocationManager()
    private let motionManager = CMMotionActivityManager()
    private let locationQueue = LocationQueue()
    private let apiClient = SSTApiClient()
    private let geoFence = SSTGeoFence()
    
    private var uploadTimer: Timer?
    private var tickTimer: Timer?
    private let uploadQueue = DispatchQueue(label: "com.smartstep.attendance.uploadQueue")
    
    private var lastFixAt: Date?
    private var outsideStreak = 0
    private var firstOutsideAt: Date?
    
    override func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
    ) -> Bool {
        GeneratedPluginRegistrant.register(with: self)
        
        UIDevice.current.isBatteryMonitoringEnabled = true
        
        let controller : FlutterViewController = window?.rootViewController as! FlutterViewController
        methodChannel = FlutterMethodChannel(name: "com.smartstep.attendance/native",
                                              binaryMessenger: controller.binaryMessenger)
        methodChannel?.setMethodCallHandler({ [weak self] (call: FlutterMethodCall, result: @escaping FlutterResult) -> Void in
            self?.handleMethodCall(call, result: result)
        })
        
        eventChannel = FlutterEventChannel(name: "com.smartstep.attendance/events",
                                           binaryMessenger: controller.binaryMessenger)
        eventChannel?.setStreamHandler(EventStreamHandler(onListen: { [weak self] (sink: @escaping FlutterEventSink) in
            // `sink` is a non-escaping closure parameter by default inside this literal;
            // it must be marked @escaping here because it is stored into `eventSink`
            // below, a property that outlives this call.
            self?.eventSink = sink
        }, onCancel: { [weak self] in
            self?.eventSink = nil
        }))
        
        setupLocationManager()
        setupNotifications()
        startTimers()
        
        return super.application(application, didFinishLaunchingWithOptions: launchOptions)
    }
    
    private func handleMethodCall(_ call: FlutterMethodCall, result: @escaping FlutterResult) {
        switch call.method {
        case "deviceUid":
            result(getDeviceUid())
        case "deviceInfo":
            result([
                "model": UIDevice.current.model,
                "brand": "Apple",
                "os_version": UIDevice.current.systemVersion,
                "platform": "ios"
            ])
        case "defaultApiBase":
            result(nil)
        case "setBaseUrl":
            if let args = call.arguments as? [String: Any], let url = args["url"] as? String {
                UserDefaults.standard.set(url, forKey: "sst_api_base_url")
                result(true)
            } else { result(false) }
        case "setSession":
            if let args = call.arguments as? [String: Any],
               let token = args["token"] as? String {
                KeychainHelper.save(key: "com.smartstep.attendance.token", value: token)
                if let config = args["config"] as? String {
                    UserDefaults.standard.set(config, forKey: "sst_session_config")
                }
                result(true)
            } else { result(false) }
        case "clearSession":
            KeychainHelper.delete(key: "com.smartstep.attendance.token")
            stopTracking()
            result(true)
        case "startTracking":
            if isShiftOpen() {
                startTracking()
            }
            result(true)
        case "stopTracking":
            stopTracking()
            result(true)
        case "setShiftOpen":
            if let args = call.arguments as? [String: Any], let open = args["open"] as? Bool {
                UserDefaults.standard.set(open, forKey: "sst_shift_open")
                if open { startTracking() } else { stopTracking() }
                result(true)
            } else { result(false) }
        case "setOutsideReasonGiven":
            if let args = call.arguments as? [String: Any], let given = args["given"] as? Bool {
                UserDefaults.standard.set(given, forKey: "sst_outside_reason_given")
                result(true)
            } else { result(false) }
        case "syncNow":
            triggerUpload()
            result(true)
        case "trackingStatus":
            let configStr = UserDefaults.standard.string(forKey: "sst_session_config") ?? "{}"
            let configData = configStr.data(using: .utf8) ?? Data()
            let cfg = (try? JSONSerialization.jsonObject(with: configData)) as? [String: Any] ?? [:]
            let settings = cfg["settings"] as? [String: Any] ?? [:]
            let intervalMin = settings["tracking_interval_min"] as? Int ?? 10
            result([
                "running": isTracking(),
                "wanted": UserDefaults.standard.bool(forKey: "sst_tracking_wanted"),
                "pending": locationQueue.count(),
                "last_fix_at": UserDefaults.standard.integer(forKey: "sst_last_fix_at_ms"),
                "last_upload_at": UserDefaults.standard.integer(forKey: "sst_last_upload_at_ms"),
                "interval_min": intervalMin
            ])
        case "integrityCheck":
            result(["ok": true, "reason": nil])
        case "isBatteryOptimised":
            result(false)
        case "requestBatteryExemption":
            result(true)
        case "openAppSettings", "openLocationSettings", "openNotificationSettings":
            if let url = URL(string: UIApplication.openSettingsURLString) {
                UIApplication.shared.open(url)
            }
            result(true)
        case "notificationsEnabled":
            UNUserNotificationCenter.current().getNotificationSettings { settings in
                DispatchQueue.main.async { result(settings.authorizationStatus == .authorized) }
            }
        case "applyScreenCapturePolicy":
            result(false)
        case "isDeviceAdminActive", "requestDeviceAdmin", "releaseDeviceAdmin", "canInstallPackages", "requestInstallPermission", "installApk":
            result(false)
        case "openUrl":
            if let args = call.arguments as? [String: Any], let urlStr = args["url"] as? String, let url = URL(string: urlStr) {
                UIApplication.shared.open(url)
                result(true)
            } else { result(false) }
        default:
            result(FlutterMethodNotImplemented)
        }
    }
    
    private func getDeviceUid() -> String {
        if let uid = KeychainHelper.load(key: "com.smartstep.attendance.device_uid") {
            return uid
        }
        let newUid = UUID().uuidString
        KeychainHelper.save(key: "com.smartstep.attendance.device_uid", value: newUid)
        return newUid
    }
    
    private func setupLocationManager() {
        locationManager.delegate = self
        locationManager.allowsBackgroundLocationUpdates = true
        locationManager.pausesLocationUpdatesAutomatically = false
        locationManager.showsBackgroundLocationIndicator = true
        locationManager.desiredAccuracy = kCLLocationAccuracyBest
        locationManager.distanceFilter = kCLDistanceFilterNone
        locationManager.requestAlwaysAuthorization()
    }
    
    private func setupNotifications() {
        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound, .badge]) { _, _ in }
    }
    
    private func isShiftOpen() -> Bool {
        return UserDefaults.standard.bool(forKey: "sst_shift_open")
    }
    
    private func isTracking() -> Bool {
        return UserDefaults.standard.bool(forKey: "sst_tracking_wanted")
    }
    
    private func startTracking() {
        UserDefaults.standard.set(true, forKey: "sst_tracking_wanted")
        locationManager.startUpdatingLocation()
    }
    
    private func stopTracking() {
        UserDefaults.standard.set(false, forKey: "sst_tracking_wanted")
        locationManager.stopUpdatingLocation()
    }
    
    private func startTimers() {
        tickTimer = Timer.scheduledTimer(withTimeInterval: 600, repeats: true) { [weak self] _ in
            self?.onTick()
        }
        uploadTimer = Timer.scheduledTimer(withTimeInterval: 60, repeats: true) { [weak self] _ in
            self?.triggerUpload()
        }
    }
    
    private func onTick() {
        if isShiftOpen() {
            if isTracking() {
                locationManager.startUpdatingLocation()
            } else {
                startTracking()
            }
            triggerUpload()
        } else {
            stopTracking()
        }
    }
    
    private func triggerUpload() {
        uploadQueue.async { [weak self] in
            guard let self = self else { return }
            let count = self.locationQueue.count()
            if count == 0 {
                _ = self.apiClient.post(path: "heartbeat", payload: [:])
                return
            }
            
            let (ids, points) = self.locationQueue.peekBatch(limit: 200)
            guard !points.isEmpty else { return }
            
            let payload: [String: Any] = ["points": points]
            let response = self.apiClient.post(path: "locations/batch", payload: payload)
            
            if response.ok {
                self.locationQueue.delete(ids: ids)
                let nowMs = Int(Date().timeIntervalSince1970 * 1000)
                UserDefaults.standard.set(nowMs, forKey: "sst_last_upload_at_ms")
            } else if response.sessionDead {
                DispatchQueue.main.async { self.stopTracking() }
                KeychainHelper.delete(key: "com.smartstep.attendance.token")
            }
        }
    }
    
    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard isShiftOpen(), let location = locations.last else { return }
        lastFixAt = Date()
        
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        formatter.locale = Locale(identifier: "en_US_POSIX")
        let recordedAt = formatter.string(from: location.timestamp)
        let nowMs = Int(Date().timeIntervalSince1970 * 1000)
        UserDefaults.standard.set(nowMs, forKey: "sst_last_fix_at_ms")
        
        let batteryLevel = Int(UIDevice.current.batteryLevel * 100)
        let isCharging = UIDevice.current.batteryState == .charging || UIDevice.current.batteryState == .full
        
        // Evaluate Geofence
        let configStr = UserDefaults.standard.string(forKey: "sst_session_config") ?? "{}"
        let configData = configStr.data(using: .utf8) ?? Data()
        let config = (try? JSONSerialization.jsonObject(with: configData)) as? [String: Any] ?? [:]
        let settings = config["settings"] as? [String: Any] ?? [:]
        let bufferM = settings["geofence_buffer_m"] as? Double ?? 50.0
        var areas: [Area] = []
        
        // Try "geofences" array first, then fallback to single "geofence"
        if let configAreas = config["geofences"] as? [[String: Any]] {
            for a in configAreas {
                if let area = Area.fromJson(a) { areas.append(area) }
            }
        }
        if areas.isEmpty, let single = config["geofence"] as? [String: Any] {
            if let area = Area.fromJson(single) { areas.append(area) }
        }
        
        let result = geoFence.evaluateAll(lat: location.coordinate.latitude, lng: location.coordinate.longitude, areas: areas, bufferM: bufferM)
        
        if result.inside {
            outsideStreak = 0
            firstOutsideAt = nil
        } else {
            if outsideStreak == 0 {
                firstOutsideAt = Date()
            }
            outsideStreak += 1
            if outsideStreak >= 5, let first = firstOutsideAt, Date().timeIntervalSince(first) >= 150 {
                sendFenceEvent(outside: true, distance: result.distanceM, areaName: result.areaName ?? "")
                sendLocalNotification(title: "Geofence Breach", body: "You have left the allowed area.")
            }
        }
        
        let point: [String: Any] = [
            "client_uid": UUID().uuidString,
            "lat": location.coordinate.latitude,
            "lng": location.coordinate.longitude,
            "accuracy": location.horizontalAccuracy,
            "altitude": location.altitude,
            "speed": location.speed,
            "heading": location.course,
            "battery": batteryLevel,
            "is_charging": isCharging ? 1 : 0,
            "is_mock": 0,
            "provider": "gps",
            "recorded_at": recordedAt,
            "inside_fence": result.inside ? 1 : 0,
            "distance_m": result.distanceM,
            "outside_streak": outsideStreak,
            "real_lat": location.coordinate.latitude,
            "real_lng": location.coordinate.longitude
        ]
        
        locationQueue.enqueue(point: point)
    }
    
    private func sendFenceEvent(outside: Bool, distance: Int, areaName: String) {
        DispatchQueue.main.async {
            self.eventSink?([
                "type": "fence",
                "outside": outside,
                "distance_m": distance,
                "area_name": areaName
            ])
        }
    }
    
    private func sendLocalNotification(title: String, body: String) {
        let content = UNMutableNotificationContent()
        content.title = title
        content.body = body
        content.sound = .default
        let request = UNNotificationRequest(identifier: UUID().uuidString, content: content, trigger: nil)
        UNUserNotificationCenter.current().add(request)
    }
}

class EventStreamHandler: NSObject, FlutterStreamHandler {
    private var onListen: (FlutterEventSink) -> Void
    private var onCancel: () -> Void
    
    init(onListen: @escaping (FlutterEventSink) -> Void, onCancel: @escaping () -> Void) {
        self.onListen = onListen
        self.onCancel = onCancel
    }
    
    func onListen(withArguments arguments: Any?, eventSink events: @escaping FlutterEventSink) -> FlutterError? {
        onListen(events)
        return nil
    }
    
    func onCancel(withArguments arguments: Any?) -> FlutterError? {
        onCancel()
        return nil
    }
}

class KeychainHelper {
    static func save(key: String, value: String) {
        let data = Data(value.utf8)
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: key,
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlock
        ]
        SecItemDelete(query as CFDictionary)
        SecItemAdd(query as CFDictionary, nil)
    }
    
    static func load(key: String) -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var item: CFTypeRef?
        if SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
           let data = item as? Data {
            return String(data: data, encoding: .utf8)
        }
        return nil
    }
    
    static func delete(key: String) {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: key
        ]
        SecItemDelete(query as CFDictionary)
    }
}

class LocationQueue {
    private var db: OpaquePointer?
    private let maxRows = 10000
    
    init() {
        openDatabase()
        createTable()
    }
    
    private func openDatabase() {
        let fileURL = try! FileManager.default
            .url(for: .documentDirectory, in: .userDomainMask, appropriateFor: nil, create: false)
            .appendingPathComponent("sst_attendance_queue.db")
        if sqlite3_open(fileURL.path, &db) != SQLITE_OK {
            print("Error opening database")
        }
    }
    
    private func createTable() {
        let createQuery = """
        CREATE TABLE IF NOT EXISTS queued_points (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_uid TEXT NOT NULL UNIQUE,
            lat REAL NOT NULL,
            lng REAL NOT NULL,
            accuracy REAL,
            altitude REAL,
            speed REAL,
            heading REAL,
            battery INTEGER,
            is_charging INTEGER,
            is_mock INTEGER NOT NULL DEFAULT 0,
            provider TEXT,
            recorded_at TEXT NOT NULL,
            inside_fence INTEGER,
            distance_m INTEGER,
            outside_streak INTEGER NOT NULL DEFAULT 0,
            real_lat REAL,
            real_lng REAL
        );
        """
        if sqlite3_exec(db, createQuery, nil, nil, nil) != SQLITE_OK {
            print("Error creating table")
        }
    }
    
    func enqueue(point: [String: Any]) {
        let query = """
        INSERT OR IGNORE INTO queued_points (client_uid, lat, lng, accuracy, altitude, speed, heading, battery, is_charging, is_mock, provider, recorded_at, inside_fence, distance_m, outside_streak, real_lat, real_lng)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
        """
        var statement: OpaquePointer?
        if sqlite3_prepare_v2(db, query, -1, &statement, nil) == SQLITE_OK {
            let clientUid = (point["client_uid"] as? String ?? UUID().uuidString) as NSString
            sqlite3_bind_text(statement, 1, clientUid.utf8String, -1, unsafeBitCast(-1, to: sqlite3_destructor_type.self))
            sqlite3_bind_double(statement, 2, point["lat"] as? Double ?? 0)
            sqlite3_bind_double(statement, 3, point["lng"] as? Double ?? 0)
            sqlite3_bind_double(statement, 4, point["accuracy"] as? Double ?? 0)
            sqlite3_bind_double(statement, 5, point["altitude"] as? Double ?? 0)
            sqlite3_bind_double(statement, 6, point["speed"] as? Double ?? 0)
            sqlite3_bind_double(statement, 7, point["heading"] as? Double ?? 0)
            sqlite3_bind_int(statement, 8, Int32(point["battery"] as? Int ?? 0))
            sqlite3_bind_int(statement, 9, Int32(point["is_charging"] as? Int ?? 0))
            sqlite3_bind_int(statement, 10, Int32(point["is_mock"] as? Int ?? 0))
            let provider = (point["provider"] as? String ?? "gps") as NSString
            sqlite3_bind_text(statement, 11, provider.utf8String, -1, unsafeBitCast(-1, to: sqlite3_destructor_type.self))
            let recordedAt = (point["recorded_at"] as? String ?? "") as NSString
            sqlite3_bind_text(statement, 12, recordedAt.utf8String, -1, unsafeBitCast(-1, to: sqlite3_destructor_type.self))
            sqlite3_bind_int(statement, 13, Int32(point["inside_fence"] as? Int ?? 0))
            sqlite3_bind_int(statement, 14, Int32(point["distance_m"] as? Int ?? 0))
            sqlite3_bind_int(statement, 15, Int32(point["outside_streak"] as? Int ?? 0))
            sqlite3_bind_double(statement, 16, point["real_lat"] as? Double ?? 0)
            sqlite3_bind_double(statement, 17, point["real_lng"] as? Double ?? 0)
            sqlite3_step(statement)
        }
        sqlite3_finalize(statement)
        
        trimRows()
    }
    
    private func trimRows() {
        let query = "DELETE FROM queued_points WHERE id NOT IN (SELECT id FROM queued_points ORDER BY id DESC LIMIT \(maxRows))"
        sqlite3_exec(db, query, nil, nil, nil)
    }
    
    func peekBatch(limit: Int = 200) -> ([Int], [[String: Any]]) {
        let query = "SELECT * FROM queued_points ORDER BY id ASC LIMIT \(limit)"
        var statement: OpaquePointer?
        var ids: [Int] = []
        var points: [[String: Any]] = []
        
        if sqlite3_prepare_v2(db, query, -1, &statement, nil) == SQLITE_OK {
            while sqlite3_step(statement) == SQLITE_ROW {
                let id = Int(sqlite3_column_int(statement, 0))
                ids.append(id)
                var pt: [String: Any] = [:]
                pt["client_uid"] = String(cString: sqlite3_column_text(statement, 1))
                pt["lat"] = sqlite3_column_double(statement, 2)
                pt["lng"] = sqlite3_column_double(statement, 3)
                pt["accuracy"] = sqlite3_column_double(statement, 4)
                pt["altitude"] = sqlite3_column_double(statement, 5)
                pt["speed"] = sqlite3_column_double(statement, 6)
                pt["heading"] = sqlite3_column_double(statement, 7)
                pt["battery"] = Int(sqlite3_column_int(statement, 8))
                pt["is_charging"] = Int(sqlite3_column_int(statement, 9))
                pt["is_mock"] = Int(sqlite3_column_int(statement, 10)) != 0
                pt["provider"] = String(cString: sqlite3_column_text(statement, 11))
                pt["recorded_at"] = String(cString: sqlite3_column_text(statement, 12))
                pt["inside_fence"] = Int(sqlite3_column_int(statement, 13)) != 0
                pt["distance_m"] = Int(sqlite3_column_int(statement, 14))
                pt["outside_streak"] = Int(sqlite3_column_int(statement, 15))
                pt["real_lat"] = sqlite3_column_double(statement, 16)
                pt["real_lng"] = sqlite3_column_double(statement, 17)
                points.append(pt)
            }
        }
        sqlite3_finalize(statement)
        return (ids, points)
    }
    
    func delete(ids: [Int]) {
        guard !ids.isEmpty else { return }
        let idString = ids.map { String($0) }.joined(separator: ",")
        let query = "DELETE FROM queued_points WHERE id IN (\(idString))"
        sqlite3_exec(db, query, nil, nil, nil)
    }
    
    func count() -> Int {
        let query = "SELECT COUNT(*) FROM queued_points"
        var statement: OpaquePointer?
        var total = 0
        if sqlite3_prepare_v2(db, query, -1, &statement, nil) == SQLITE_OK {
            if sqlite3_step(statement) == SQLITE_ROW {
                total = Int(sqlite3_column_int(statement, 0))
            }
        }
        sqlite3_finalize(statement)
        return total
    }
    
    func clear() {
        sqlite3_exec(db, "DELETE FROM queued_points", nil, nil, nil)
    }
}

class SSTApiClient {
    struct Response {
        let code: Int
        let body: [String: Any]?
        let ok: Bool
        let sessionDead: Bool
    }
    
    func post(path: String, payload: [String: Any]) -> Response {
        guard let baseUrl = UserDefaults.standard.string(forKey: "sst_api_base_url"),
              !baseUrl.isEmpty,
              let url = URL(string: baseUrl + (baseUrl.hasSuffix("/") ? "" : "/") + path) else {
            return Response(code: 0, body: nil, ok: false, sessionDead: false)
        }
        let token = KeychainHelper.load(key: "com.smartstep.attendance.token")
        
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 15.0
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token = token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
            request.setValue(token, forHTTPHeaderField: "X-App-Token")
        }
        
        if let data = try? JSONSerialization.data(withJSONObject: payload) {
            request.httpBody = data
        }
        
        let semaphore = DispatchSemaphore(value: 0)
        var responseCode = -1
        var responseBody: [String: Any]? = nil
        var isOk = false
        var isDead = false
        
        let task = URLSession.shared.dataTask(with: request) { data, response, error in
            defer { semaphore.signal() }
            if let httpResponse = response as? HTTPURLResponse {
                responseCode = httpResponse.statusCode
                isOk = (200...299).contains(responseCode)
                
                if let data = data, let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                    responseBody = json
                    if responseCode == 401 || (json["error_code"] as? String).map({ ["INVALID_TOKEN", "TOKEN_REVOKED", "TOKEN_EXPIRED", "DEVICE_RESET", "ACCOUNT_DISABLED", "UNAUTHENTICATED"].contains($0) }) == true {
                        isDead = true
                    }
                }
            }
        }
        task.resume()
        _ = semaphore.wait(timeout: .now() + 30.0)
        
        return Response(code: responseCode, body: responseBody, ok: isOk, sessionDead: isDead)
    }
}

struct Area {
    let id: Int
    let name: String
    let type: String
    let centerLat: Double?
    let centerLng: Double?
    let radiusM: Double?
    let polygon: [[Double]]
    
    static func fromJson(_ json: [String: Any]) -> Area? {
        guard let id = json["id"] as? Int, id > 0 else { return nil }
        let name = json["name"] as? String ?? ""
        let type = json["type"] as? String ?? "circle"
        let centerLat = json["center_lat"] as? Double
        let centerLng = json["center_lng"] as? Double
        let radiusM = json["radius_m"] as? Double
        
        var polygon: [[Double]] = []
        if let raw = json["polygon"] as? [[Any]] {
            for pair in raw {
                if pair.count >= 2, let lat = pair[0] as? Double, let lng = pair[1] as? Double {
                    polygon.append([lat, lng])
                }
            }
        }
        return Area(id: id, name: name, type: type, centerLat: centerLat, centerLng: centerLng, radiusM: radiusM, polygon: polygon)
    }
}

struct FenceResult {
    let inside: Bool
    let distanceM: Int
    let areaId: Int?
    let areaName: String?
}

class SSTGeoFence {
    private let earthRadiusM = 6371000.0
    
    func haversine(lat1: Double, lng1: Double, lat2: Double, lng2: Double) -> Double {
        let phi1 = lat1 * .pi / 180
        let phi2 = lat2 * .pi / 180
        let dPhi = (lat2 - lat1) * .pi / 180
        let dLambda = (lng2 - lng1) * .pi / 180
        let a = sin(dPhi/2) * sin(dPhi/2) + cos(phi1) * cos(phi2) * sin(dLambda/2) * sin(dLambda/2)
        return 2 * earthRadiusM * asin(min(1.0, sqrt(a)))
    }
    
    func validCoords(lat: Double, lng: Double) -> Bool {
        if lat.isNaN || lng.isNaN { return false }
        if lat < -90 || lat > 90 || lng < -180 || lng > 180 { return false }
        if abs(lat) < 0.00001 && abs(lng) < 0.00001 { return false }
        return true
    }
    
    private func pointInPolygon(lat: Double, lng: Double, ring: [[Double]]) -> Bool {
        var inside = false
        var j = ring.count - 1
        for i in 0..<ring.count {
            let latI = ring[i][0]; let lngI = ring[i][1]
            let latJ = ring[j][0]; let lngJ = ring[j][1]
            if (lngI > lng) != (lngJ > lng) {
                let latAtLng = latI + (lng - lngI) * (latJ - latI) / (lngJ - lngI)
                if lat < latAtLng { inside = !inside }
            }
            j = i
        }
        return inside
    }
    
    private func distanceToSegment(lat: Double, lng: Double, aLat: Double, aLng: Double, bLat: Double, bLng: Double) -> Double {
        let latScale = earthRadiusM * .pi / 180.0
        let lngScale = latScale * cos(lat * .pi / 180.0)
        let px = (lng - aLng) * lngScale
        let py = (lat - aLat) * latScale
        let bx = (bLng - aLng) * lngScale
        let by = (bLat - aLat) * latScale
        let lengthSq = bx * bx + by * by
        if lengthSq <= 0.0 { return sqrt(px * px + py * py) }
        let t = max(0.0, min(1.0, (px * bx + py * by) / lengthSq))
        let dx = px - t * bx
        let dy = py - t * by
        return sqrt(dx * dx + dy * dy)
    }
    
    private func distanceToPolygon(lat: Double, lng: Double, ring: [[Double]]) -> Double {
        var minimum = Double.greatestFiniteMagnitude
        for i in 0..<ring.count {
            let a = ring[i]
            let b = ring[(i + 1) % ring.count]
            minimum = min(minimum, distanceToSegment(lat: lat, lng: lng, aLat: a[0], aLng: a[1], bLat: b[0], bLng: b[1]))
        }
        return minimum == Double.greatestFiniteMagnitude ? 0.0 : minimum
    }
    
    func evaluate(lat: Double, lng: Double, area: Area, bufferM: Double) -> FenceResult {
        if area.type == "polygon" {
            if area.polygon.count < 3 { return FenceResult(inside: true, distanceM: 0, areaId: area.id, areaName: area.name) }
            if pointInPolygon(lat: lat, lng: lng, ring: area.polygon) {
                return FenceResult(inside: true, distanceM: 0, areaId: area.id, areaName: area.name)
            }
            let out = distanceToPolygon(lat: lat, lng: lng, ring: area.polygon)
            return FenceResult(inside: out <= bufferM, distanceM: Int(out), areaId: area.id, areaName: area.name)
        }
        
        // Circle
        guard let centerLat = area.centerLat, let centerLng = area.centerLng, let radius = area.radiusM else {
            return FenceResult(inside: true, distanceM: 0, areaId: area.id, areaName: area.name)
        }
        let distance = haversine(lat1: lat, lng1: lng, lat2: centerLat, lng2: centerLng)
        let out = max(0.0, distance - radius)
        return FenceResult(inside: out <= bufferM, distanceM: Int(out), areaId: area.id, areaName: area.name)
    }
    
    func evaluateAll(lat: Double, lng: Double, areas: [Area], bufferM: Double) -> FenceResult {
        if areas.isEmpty { return FenceResult(inside: true, distanceM: 0, areaId: nil, areaName: nil) }
        
        var nearest: FenceResult?
        for area in areas {
            let res = evaluate(lat: lat, lng: lng, area: area, bufferM: bufferM)
            if res.inside { return res }
            if nearest == nil || res.distanceM < nearest!.distanceM {
                nearest = res
            }
        }
        return nearest ?? FenceResult(inside: true, distanceM: 0, areaId: nil, areaName: nil)
    }
}

