import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Release signing is read from android/key.properties, which is deliberately not
// committed. Without it the release build falls back to the debug key so a build
// still produces an installable APK.
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    FileInputStream(keystorePropertiesFile).use { keystoreProperties.load(it) }
}

android {
    namespace = "com.sst.attendance"
    // 36 is what this machine's SDK provides (Android 16), and Play requires a
    // recent target anyway.
    compileSdk = 36
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.sst.attendance"
        // 24 rather than the Flutter default: EncryptedSharedPreferences and the
        // service APIs used are comfortable at 24, and it covers old field handsets.
        minSdk = 24
        targetSdk = 36
        versionCode = flutter.versionCode
        versionName = flutter.versionName

        // The API base the app talks to. Override per build with -Papi.Base=...
        // so a staging APK needs no source change.
        //
        // This must be the real server, never a placeholder. The app attaches the
        // employee's bearer token to every request, so a default that points at a
        // domain somebody else owns does not merely fail — it hands that token to a
        // stranger the moment the on-device setting is missing.
        val apiBase = (project.findProperty("api.Base") as String?)
            ?: "https://sstqa.com/Attendance/Website/api/v1"
        buildConfigField("String", "DEFAULT_API_BASE", "\"$apiBase\"")
    }

    buildFeatures {
        buildConfig = true
    }

    signingConfigs {
        create("release") {
            if (keystorePropertiesFile.exists()) {
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
                storeFile = file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (keystorePropertiesFile.exists()) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

dependencies {
    // EncryptedSharedPreferences, used to hold the bearer token and device uid.
    implementation("androidx.security:security-crypto:1.1.0-alpha06")

    // Fused location. Raw LocationManager hands back each provider's opinion
    // separately, so the service saw a coarse network fix and a precise GPS fix as two
    // peers and had to referee between them; measured on a real route, the network ones
    // wobbled 74 m while claiming +/-26 m. The fused provider does that refereeing
    // properly, with the sensors as well as the radios, and returns one position.
    //
    // No size cost: geolocator already pulls this in, so the artifact is in the APK
    // either way. Declared explicitly because a transitive dependency of another
    // module is not on this module's compile classpath.
    implementation("com.google.android.gms:play-services-location:21.3.0")
}
