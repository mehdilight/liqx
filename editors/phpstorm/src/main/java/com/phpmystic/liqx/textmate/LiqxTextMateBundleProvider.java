package com.phpmystic.liqx.textmate;

import com.intellij.ide.plugins.PluginManagerCore;
import com.intellij.openapi.extensions.PluginId;
import org.jetbrains.annotations.NotNull;
import org.jetbrains.plugins.textmate.api.TextMateBundleProvider;

import java.io.File;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.util.Collections;
import java.util.List;

public final class LiqxTextMateBundleProvider implements TextMateBundleProvider {
    @NotNull
    @Override
    public List<PluginBundle> getBundles() {
        var plugin = PluginManagerCore.getPlugin(PluginId.getId("com.phpmystic.liqx"));
        if (plugin != null) {
            Path pluginPath = plugin.getPluginPath();
            Path textmateDir = pluginPath.resolve("textmate");

            // If textmate files are not on disk yet (e.g. packed in jar), extract them
            ensureBundleFiles(textmateDir);

            if (Files.isDirectory(textmateDir)) {
                return List.of(new PluginBundle("liqx", textmateDir));
            }
        }
        return Collections.emptyList();
    }

    private void ensureBundleFiles(Path targetDir) {
        try {
            if (!Files.exists(targetDir)) {
                Files.createDirectories(targetDir);
            }

            String[] files = {"package.json", "language-configuration.json", "liqx.tmLanguage.json"};
            for (String file : files) {
                Path targetFile = targetDir.resolve(file);
                if (!Files.exists(targetFile)) {
                    try (InputStream in = getClass().getResourceAsStream("/textmate/" + file)) {
                        if (in != null) {
                            Files.copy(in, targetFile, StandardCopyOption.REPLACE_EXISTING);
                        }
                    }
                }
            }
        } catch (Exception ignored) {
        }
    }
}
