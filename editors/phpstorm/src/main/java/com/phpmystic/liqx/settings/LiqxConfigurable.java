package com.phpmystic.liqx.settings;

import com.intellij.openapi.options.Configurable;
import com.intellij.openapi.project.Project;
import com.intellij.ui.components.JBCheckBox;
import com.intellij.ui.components.JBTextField;
import com.intellij.util.ui.FormBuilder;
import org.jetbrains.annotations.Nls;
import org.jetbrains.annotations.Nullable;
import javax.swing.JComponent;
import javax.swing.JPanel;

public final class LiqxConfigurable implements Configurable {
    private final Project project;
    private final JBTextField phpPathField = new JBTextField();
    private final JBTextField lspCommandField = new JBTextField();
    private final JBCheckBox lspEnabledCheckBox = new JBCheckBox("Enable Liqx Language Server (LSP)");
    private JPanel mainPanel;

    public LiqxConfigurable(Project project) {
        this.project = project;
    }

    @Nls(capitalization = Nls.Capitalization.Title)
    @Override
    public String getDisplayName() {
        return "Liqx";
    }

    @Nullable
    @Override
    public JComponent createComponent() {
        mainPanel = FormBuilder.createFormBuilder()
            .addComponent(lspEnabledCheckBox)
            .addLabeledComponent("PHP executable path:", phpPathField)
            .addLabeledComponent("LSP command / executable:", lspCommandField)
            .addComponentFillVertically(new JPanel(), 0)
            .getPanel();

        return mainPanel;
    }

    @Override
    public boolean isModified() {
        LiqxSettings settings = LiqxSettings.getInstance(project);
        return lspEnabledCheckBox.isSelected() != settings.lspEnabled
            || !phpPathField.getText().equals(settings.phpPath)
            || !lspCommandField.getText().equals(settings.lspCommand);
    }

    @Override
    public void apply() {
        LiqxSettings settings = LiqxSettings.getInstance(project);
        settings.lspEnabled = lspEnabledCheckBox.isSelected();
        settings.phpPath = phpPathField.getText().trim();
        settings.lspCommand = lspCommandField.getText().trim();
    }

    @Override
    public void reset() {
        LiqxSettings settings = LiqxSettings.getInstance(project);
        lspEnabledCheckBox.setSelected(settings.lspEnabled);
        phpPathField.setText(settings.phpPath);
        lspCommandField.setText(settings.lspCommand);
    }
}
