package com.phpmystic.liqx.lsp;

import com.intellij.openapi.project.Project;
import com.intellij.openapi.vfs.VirtualFile;
import com.intellij.platform.lsp.api.LspServerSupportProvider;
import com.phpmystic.liqx.settings.LiqxSettings;
import org.jetbrains.annotations.NotNull;

public final class LiqxLspServerSupportProvider implements LspServerSupportProvider {
    @Override
    public void fileOpened(@NotNull Project project, @NotNull VirtualFile file, @NotNull LspServerStarter serverStarter) {
        String ext = file.getExtension();
        if (ext != null && ext.equalsIgnoreCase("liqx")) {
            LiqxSettings settings = LiqxSettings.getInstance(project);
            if (settings.lspEnabled) {
                serverStarter.ensureServerStarted(new LiqxLspServerDescriptor(project));
            }
        }
    }
}
