package com.phpmystic.liqx.navigation;

import com.intellij.openapi.project.Project;
import com.intellij.openapi.util.TextRange;
import com.intellij.openapi.vfs.VirtualFile;
import com.intellij.patterns.PlatformPatterns;
import com.intellij.psi.*;
import com.intellij.psi.search.FileTypeIndex;
import com.intellij.psi.search.GlobalSearchScope;
import com.intellij.util.ProcessingContext;
import com.phpmystic.liqx.LiqxFileType;
import org.jetbrains.annotations.NotNull;
import org.jetbrains.annotations.Nullable;

import java.util.ArrayList;
import java.util.Collection;
import java.util.List;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public final class LiqxReferenceContributor extends PsiReferenceContributor {

    private static final Pattern COMPONENT_TAG_PATTERN = Pattern.compile("<([A-Z][a-zA-Z0-9_-]*)\\b");
    private static final Pattern LOCAL_NAMED_TEMPLATE = Pattern.compile("<template\\s+[^>]*name\\s*=\\s*[\"']([^\"']+)[\"']", Pattern.CASE_INSENSITIVE);

    @Override
    public void registerReferenceProviders(@NotNull PsiReferenceRegistrar registrar) {
        registrar.registerReferenceProvider(
            PlatformPatterns.psiElement(),
            new PsiReferenceProvider() {
                @Override
                public PsiReference @NotNull [] getReferencesByElement(@NotNull PsiElement element, @NotNull ProcessingContext context) {
                    PsiFile file = element.getContainingFile();
                    if (file == null || file.getVirtualFile() == null) {
                        return PsiReference.EMPTY_ARRAY;
                    }

                    String ext = file.getVirtualFile().getExtension();
                    if (ext == null || !ext.equalsIgnoreCase("liqx")) {
                        return PsiReference.EMPTY_ARRAY;
                    }

                    String text = element.getText();
                    if (text == null || text.isEmpty()) {
                        return PsiReference.EMPTY_ARRAY;
                    }

                    List<PsiReference> refs = new ArrayList<>();
                    Matcher matcher = COMPONENT_TAG_PATTERN.matcher(text);

                    while (matcher.find()) {
                        String name = matcher.group(1);
                        if (isControlTag(name)) {
                            continue;
                        }
                        int start = matcher.start(1);
                        int end = matcher.end(1);
                        refs.add(new LiqxComponentReference(element, new TextRange(start, end), name, file));
                    }

                    return refs.toArray(new PsiReference[0]);
                }
            }
        );
    }

    private static boolean isControlTag(String name) {
        String lower = name.toLowerCase();
        return lower.equals("if") || lower.equals("else") || lower.equals("elseif") ||
               lower.equals("show") || lower.equals("switch") || lower.equals("match") ||
               lower.equals("fallback") || lower.equals("template") || lower.equals("style") ||
               lower.equals("script") || lower.equals("schema");
    }

    public static final class LiqxComponentReference extends PsiReferenceBase<PsiElement> {
        private final String componentName;
        private final PsiFile file;

        public LiqxComponentReference(@NotNull PsiElement element, @NotNull TextRange range, @NotNull String componentName, @NotNull PsiFile file) {
            super(element, range, true);
            this.componentName = componentName;
            this.file = file;
        }

        @Override
        public @Nullable PsiElement resolve() {
            // 1. Check local named sub-template in the same file
            PsiElement local = findLocalNamedTemplate(file, componentName);
            if (local != null) {
                return local;
            }

            // 2. Check external snippets/blocks in project
            Project project = file.getProject();
            String kebab = pascalToKebab(componentName);
            Collection<VirtualFile> liqxFiles = FileTypeIndex.getFiles(LiqxFileType.INSTANCE, GlobalSearchScope.projectScope(project));

            for (VirtualFile vf : liqxFiles) {
                if (vf.equals(file.getVirtualFile())) {
                    continue;
                }
                String nameWithoutExt = vf.getNameWithoutExtension();
                if (nameWithoutExt.equalsIgnoreCase(kebab) || nameWithoutExt.equalsIgnoreCase(componentName)) {
                    PsiFile targetPsi = PsiManager.getInstance(project).findFile(vf);
                    if (targetPsi != null) {
                        return targetPsi;
                    }
                }
            }

            return null;
        }
    }

    private static @Nullable PsiElement findLocalNamedTemplate(PsiFile file, String name) {
        String text = file.getText();
        Matcher matcher = LOCAL_NAMED_TEMPLATE.matcher(text);
        while (matcher.find()) {
            String tName = matcher.group(1);
            if (tName.equalsIgnoreCase(name)) {
                int targetOffset = matcher.start(1);
                return new LiqxGotoDeclarationHandler.LocalTemplateElement(file, name, targetOffset);
            }
        }
        return null;
    }

    private static String pascalToKebab(String str) {
        return str.replaceAll("([a-z0-9])([A-Z])", "$1-$2").toLowerCase();
    }
}
