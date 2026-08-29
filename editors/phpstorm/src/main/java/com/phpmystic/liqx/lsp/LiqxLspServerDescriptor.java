package com.phpmystic.liqx.lsp;

import com.intellij.execution.configurations.GeneralCommandLine;
import com.intellij.openapi.project.Project;
import com.intellij.openapi.vfs.VirtualFile;
import com.intellij.platform.lsp.api.ProjectWideLspServerDescriptor;
import com.phpmystic.liqx.settings.LiqxSettings;
import org.jetbrains.annotations.NotNull;
import java.nio.charset.StandardCharsets;

public final class LiqxLspServerDescriptor extends ProjectWideLspServerDescriptor {
    public LiqxLspServerDescriptor(@NotNull Project project) {
        super(project, "Liqx");
    }

    @Override
    public boolean isSupportedFile(@NotNull VirtualFile file) {
        String ext = file.getExtension();
        return ext != null && ext.equalsIgnoreCase("liqx");
    }

    @NotNull
    @Override
    public GeneralCommandLine createCommandLine() {
        LiqxSettings settings = LiqxSettings.getInstance(getProject());
        String phpPath = settings.phpPath != null && !settings.phpPath.isBlank() ? settings.phpPath : "php";
        String lspCommand = settings.lspCommand != null && !settings.lspCommand.isBlank() ? settings.lspCommand : "bin/obelisk lsp";

        String basePath = getProject().getBasePath();
        if (basePath == null) {
            basePath = ".";
        }

        String[] tokens = lspCommand.trim().split("\\s+");

        GeneralCommandLine cmd = new GeneralCommandLine()
            .withWorkDirectory(basePath)
            .withCharset(StandardCharsets.UTF_8);

        cmd.setExePath(phpPath);
        for (String token : tokens) {
            if (!token.isBlank()) {
                cmd.addParameter(token);
            }
        }

        return cmd;
    }
}
