plugins {
    id("java")
    id("org.jetbrains.kotlin.jvm") version "1.9.24"
    id("org.jetbrains.intellij.platform") version "2.1.0"
}

group = "com.phpmystic"
version = "0.2.0"

repositories {
    mavenCentral()
    intellijPlatform {
        defaultRepositories()
    }
}

dependencies {
    intellijPlatform {
        phpstorm("2024.1")
        bundledPlugin("com.intellij.platform.lsp")
        bundledPlugin("org.jetbrains.plugins.textmate")
        pluginVerifier()
        zipSigner()
    }
    implementation("org.jetbrains.kotlin:kotlin-stdlib")
}

kotlin {
    jvmToolchain(17)
}

intellijPlatform {
    pluginConfiguration {
        id = "com.phpmystic.liqx"
        name = "Liqx"
        version = project.version.toString()
        description = """
            Support for the Liqx template language (.liqx) in PhpStorm and IntelliJ IDEA.
            Provides real-time LSP diagnostics, context-aware autocompletion (filters, drop members, globals),
            hover documentation, and TextMate syntax highlighting.
        """.trimIndent()
        vendor {
            name = "Phpmystic"
            url = "https://github.com/mehdilight/liqx"
        }
        ideaVersion {
            sinceBuild = "232"
            untilBuild = "243.*"
        }
    }
}
