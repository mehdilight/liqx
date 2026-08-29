package com.phpmystic.liqx.settings;

import com.intellij.openapi.components.PersistentStateComponent;
import com.intellij.openapi.components.Service;
import com.intellij.openapi.components.State;
import com.intellij.openapi.components.Storage;
import com.intellij.openapi.project.Project;
import com.intellij.util.xmlb.XmlSerializerUtil;
import org.jetbrains.annotations.NotNull;
import org.jetbrains.annotations.Nullable;

@Service(Service.Level.PROJECT)
@State(
    name = "LiqxSettings",
    storages = {@Storage("liqx.xml")}
)
public final class LiqxSettings implements PersistentStateComponent<LiqxSettings> {
    public String phpPath = "php";
    public String lspCommand = "bin/obelisk lsp";
    public boolean lspEnabled = true;

    @Nullable
    @Override
    public LiqxSettings getState() {
        return this;
    }

    @Override
    public void loadState(@NotNull LiqxSettings state) {
        XmlSerializerUtil.copyBean(state, this);
    }

    public static LiqxSettings getInstance(@NotNull Project project) {
        return project.getService(LiqxSettings.class);
    }
}
