import java.util.Properties
import org.jetbrains.kotlin.gradle.dsl.JvmTarget

val firmaProperties = Properties().apply {
    val ruta = System.getenv("ACERO360_ENTREGAS_FIRMA")
        ?: (System.getProperty("user.home") + "/.config/acero360-entregas/keystore.properties")
    val archivo = file(ruta)
    if (archivo.exists()) {
        archivo.inputStream().use { load(it) }
    }
}

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "mx.com.iguzman.acero360.entregas"
    compileSdk = 36

    defaultConfig {
        applicationId = "mx.com.iguzman.acero360.entregas"
        minSdk = 26
        targetSdk = 36
        versionCode = 3
        versionName = "1.2.0"
        vectorDrawables { useSupportLibrary = true }
    }

    signingConfigs {
        if (firmaProperties.getProperty("storeFile") != null) {
            create("release") {
                storeFile = file(firmaProperties.getProperty("storeFile"))
                storePassword = firmaProperties.getProperty("storePassword")
                keyAlias = firmaProperties.getProperty("keyAlias")
                keyPassword = firmaProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfigs.findByName("release")?.let { signingConfig = it }
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = JvmTarget.JVM_17
    }
}

dependencies {
    implementation(platform("androidx.compose:compose-bom:2025.09.00"))
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-compose:1.10.1")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.9.2")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.9.2")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.9.2")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-core")
    implementation("androidx.biometric:biometric:1.1.0")
    implementation("androidx.fragment:fragment-ktx:1.8.9")
    implementation("io.coil-kt:coil-compose:2.7.0")
    implementation("androidx.work:work-runtime-ktx:2.10.1")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
    implementation("androidx.exifinterface:exifinterface:1.3.7")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
