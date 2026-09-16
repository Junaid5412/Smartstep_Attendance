# The tracking service, boot receiver and MethodChannel entry points are only
# referenced from the manifest and from Dart, so R8 cannot see the references and
# would otherwise strip them from the release build.
-keep class com.sst.attendance.TrackingService { *; }
-keep class com.sst.attendance.BootReceiver { *; }
-keep class com.sst.attendance.MainActivity { *; }

# org.json is used by the service to parse config and build payloads.
-keep class org.json.** { *; }

-dontwarn io.flutter.embedding.**
